<?php

require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Exception\RequestException;

set_time_limit(0); // Set execution time limit to infinite

$mailchimpApiKey = 'd8d6f9975a22a99c8615b8c7af73ec5d-us13';
$mailchimpServerPrefix = 'us13'; 
$mailchimpListId = '03d9ffa12a'; 

$notionToken = 'secret_mllTPJkeHOfjETPvBCe4DS6B8w9pIwbSyHy5QrBcC9Z';
$notionDatabaseId = 'b0bcab0a17994da6a37d484ce8d83d72';

$client = new Client();

try {
    $members = [];
    $offset = 0;
    $count = 100;

    // Fetch Mailchimp Audience Data with pagination
    do {
        $response = $client->request('GET', "https://$mailchimpServerPrefix.api.mailchimp.com/3.0/lists/$mailchimpListId/members", [
            'auth' => ['anystring', $mailchimpApiKey],
            'query' => [
                'offset' => $offset,
                'count' => $count,
            ],
        ]);

        $mailchimpData = json_decode($response->getBody()->getContents(), true);
        $members = array_merge($members, $mailchimpData['members']);
        $offset += $count;

        echo "Fetched " . count($mailchimpData['members']) . " members, total so far: " . count($members) . "\n";
        
    } while (count($mailchimpData['members']) == $count);

    echo "Total members fetched: " . count($members) . "\n";

    if (empty($members)) {
        echo "No members found in the Mailchimp audience.";
        exit();
    }

    $promises = [];

    $batchSize = 100;
    $batches = array_chunk($members, $batchSize);

    foreach ($batches as $batch) {
        $filterConditions = array_map(function($member) {
            return [
                'property' => 'Email',
                'email' => [
                    'equals' => $member['email_address'],
                ],
            ];
        }, $batch);

        try {
            $checkResponse = $client->request('POST', 'https://api.notion.com/v1/databases/'.$notionDatabaseId.'/query', [
                'headers' => [
                    'Authorization' => "Bearer $notionToken",
                    'Content-Type' => 'application/json',
                    'Notion-Version' => '2022-06-28',
                ],
                'json' => [
                    'filter' => [
                        'or' => $filterConditions
                    ],
                ],
            ]);
            
            $checkData = json_decode($checkResponse->getBody()->getContents(), true);
            $existingEmails = array_column($checkData['results'], 'properties.Email.title[0].text.content');

            foreach ($batch as $member) {
                $email = $member['email_address'];

                if (in_array($email, $existingEmails)) {
                    echo "Skipped: $email already exists in Notion\n";
                    continue;
                }

                $firstName = $member['merge_fields']['FNAME'] ?? '';
                $lastName = $member['merge_fields']['LNAME'] ?? '';

                // Prepare Notion Data for each member
                $notionData = [
                    'parent' => ['database_id' => $notionDatabaseId],
                    'properties' => [
                        'Email' => [
                            'title' => [
                                [
                                    'text' => [
                                        'content' => $email,
                                    ],
                                ],
                            ],
                        ],
                        'first_name' => [
                            'rich_text' => [
                                [
                                    'text' => [
                                        'content' => $firstName,
                                    ],
                                ],
                            ],
                        ],
                        'last_name' => [
                            'rich_text' => [
                                [
                                    'text' => [
                                        'content' => $lastName,
                                    ],
                                ],
                            ],
                        ],
                    ],            
                ];

                // Queue the request as a promise
                $promises[] = $client->postAsync('https://api.notion.com/v1/pages', [
                    'headers' => [
                        'Authorization' => "Bearer $notionToken",
                        'Content-Type' => 'application/json',
                        'Notion-Version' => '2022-06-28',
                    ],
                    'json' => $notionData,
                ])->then(function($response) use ($email) {
                    if ($response->getStatusCode() == 200) {
                        echo "Success: Added $email to Notion\n";
                    } else {
                        echo "Error: Could not add $email to Notion\n";
                    }
                });
            }
        } catch (RequestException $e) {
            echo 'Caught exception: ', $e->getMessage(), "\n";
            if ($e->getResponse()) {
                echo 'Response: ', $e->getResponse()->getBody()->getContents(), "\n";
            }
        }
    }

    // Wait for all promises to settle
    Utils::settle($promises)->wait();

} catch (Exception $e) {
    if ($e instanceof \GuzzleHttp\Exception\ClientException) {
        $responseBody = $e->getResponse()->getBody()->getContents();
        echo 'Caught exception: ', $responseBody, "\n";
    } else {
        echo 'Caught exception: ', $e->getMessage(), "\n";
    }
}

?>
