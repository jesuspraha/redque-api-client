<?php

namespace JesusPraha\RedqueApiClient;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart; 
use Symfony\Contracts\HttpClient\HttpClientInterface;

class RedqueApiClient
{
    private const BASE_URL = 'https://api.redque.com/v1';
    private const IDENTITY_URL = 'https://identity.redque.com/connect/token';

    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;
    private string $username;
    private string $password;
    private ?string $token = null;

    public function __construct(
        HttpClientInterface $httpClient,
        string $username,
        string $password,
        ?LoggerInterface $logger = null
    ) {
        $this->httpClient = $httpClient;
        $this->username = $username;
        $this->password = $password;
        $this->logger = $logger ?? new NullLogger();
    }

    public function login(): ?string
    {
        try {
            $this->logger->debug('Redque API: Attempting login for ' . $this->username);
            $response = $this->httpClient->request('POST', self::IDENTITY_URL, [
                'body' => [
                    'grant_type' => 'password',
                    'username' => $this->username,
                    'password' => $this->password,
                    'client_id' => 'api_redque',
                    'scope' => 'v1.public',
                ]
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode === 200) {
                $data = $response->toArray();
                $this->logger->info('Redque API: Login successful');
                $this->token = $data['access_token'] ?? null;
                return $this->token;
            } else {
                $this->logger->error('Redque API: Login failed with status ' . $statusCode . '. Response: ' . $response->getContent(false));
                throw new RedqueApiException('Login failed with status ' . $statusCode);
            }
        } catch (\Exception $e) {
            $this->logger->critical('Redque API: Exception during login: ' . $e->getMessage());
            throw new RedqueApiException('Exception during login: ' . $e->getMessage(), 0, $e);
        }
    }

    private function getToken(): string
    {
        if ($this->token === null) {
            $this->login();
        }

        if ($this->token === null) {
            throw new RedqueApiException('Could not obtain authentication token.');
        }

        return $this->token;
    }

    public function getAccountingUnits(string $unitId): array
    {
        try {
            $this->logger->debug('Redque API: Fetching accounting units for ID ' . $unitId);
            $response = $this->httpClient->request('POST', self::BASE_URL . '/accounting-unit/list', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getToken(),
                ],
                'json' => [
                    'filter' => [
                        'customerId' => [
                            'values' => [$unitId]
                        ]
                    ]
                ]
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode === 200) {
                $data = $response->toArray();
                $units = $data['list'] ?? [];
                $this->logger->info('Redque API: Fetched ' . count($units) . ' accounting units');
                return $units;
            } else {
                $this->logger->error('Redque API: Fetch units failed with status ' . $statusCode . '. Response: ' . $response->getContent(false));
                throw new RedqueApiException('Fetch units failed with status ' . $statusCode);
            }
        } catch (\Exception $e) {
            $this->logger->error('Redque API: Exception during fetch units: ' . $e->getMessage());
            throw new RedqueApiException('Exception during fetch units: ' . $e->getMessage(), 0, $e);
        }
    }

    public function getDocumentTypes(): array
    {
        try {
            $this->logger->debug('Redque API: Fetching document types');
            $response = $this->httpClient->request('GET', self::BASE_URL . '/document-types/active', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getToken(),
                ],
                'query' => [
                    'culture' => 'cs-CZ'
                ]
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode === 200) {
                $data = $response->toArray();
                $this->logger->info('Redque API: Successfully fetched document types');
                
                // Map documentType to code for compatibility
                return array_map(function($item) {
                    return [
                        'code' => $item['id'] ?? null,
                        'name' => $item['label'] ?? null,
                    ];
                }, $data);
            } else {
                $this->logger->error('Redque API: Fetch document types failed with status ' . $statusCode . '. Response: ' . $response->getContent(false));
                // Fallback to basic types if API fails
                return [
                    ['code' => 'czech_invoice', 'name' => 'Česká faktura'],
                    ['code' => 'receipt', 'name' => 'Účtenka'],
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error('Redque API: Exception during fetch document types: ' . $e->getMessage());
            return [
                ['code' => 'czech_invoice', 'name' => 'Česká faktura'],
                ['code' => 'receipt', 'name' => 'Účtenka'],
            ];
        }
    }

    /**
     * @param resource|string $stream
     */
    public function uploadDocument(
        $stream,
        string $filename,
        ?string $contentType,
        string $unitId,
        string $documentId,
        ?\DateTimeInterface $date = null,
        string $documentClass = 'czech_invoice',
        string $source = 'API'
    ): ?string {
        $this->logger->info('Redque API: Preparing upload for document ID ' . $documentId);

        try {
            if (is_resource($stream)) {
                rewind($stream);
            }

            $formFields = [
                'File' => new DataPart($stream, $filename, $contentType),
                'AccountingUnitId' => $unitId,
                'DocumentClass' => $documentClass,
                'Source' => $source,
                'DocumentId' => $documentId,
            ];

            if ($date) {
                $formFields['Date'] = $date->format('Y-m-d\TH:i:s\Z');
            }

            $formData = new FormDataPart($formFields);
            $headers = $formData->getPreparedHeaders()->toArray();
            $headers[] = 'Authorization: Bearer ' . $this->getToken();

            $response = $this->httpClient->request('POST', self::BASE_URL . '/documents', [
                'headers' => $headers,
                'body' => $formData->bodyToIterable(),
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode === 201 || $statusCode === 200) {
                $data = $response->toArray();
                $redqueId = $data['documentId'] ?? ($data['id'] ?? null); // Fallback to id if documentId relies on older version
                $this->logger->info('Redque API: Upload successful. Redque ID: ' . ($redqueId ?? 'unknown'));
                return $redqueId;
            } else {
                $this->logger->error('Redque API: Upload failed with status ' . $statusCode . '. Response: ' . $response->getContent(false));
                throw new RedqueApiException('Upload failed with status ' . $statusCode);
            }
        } catch (\Exception $e) {
            $this->logger->critical('Redque API: Exception during upload: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            throw new RedqueApiException('Exception during upload: ' . $e->getMessage(), 0, $e);
        }
    }

    public function getDocument(string $documentId, array $options = []): ?array
    {
        try {
            $this->logger->debug('Redque API: Fetching document ' . $documentId);
            $response = $this->httpClient->request('GET', self::BASE_URL . '/documents/' . urlencode($documentId), [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getToken(),
                ],
                'query' => $options
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode === 200) {
                $this->logger->info('Redque API: Successfully fetched document ' . $documentId);
                return $response->toArray();
            } else {
                $this->logger->error('Redque API: Fetch document failed with status ' . $statusCode . '. Response: ' . $response->getContent(false));
                throw new RedqueApiException('Fetch document failed with status ' . $statusCode);
            }
        } catch (\Exception $e) {
            $this->logger->critical('Redque API: Exception during getDocument: ' . $e->getMessage());
            throw new RedqueApiException('Exception during getDocument: ' . $e->getMessage(), 0, $e);
        }
    }
}
