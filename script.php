<?php

require 'vendor/autoload.php';

use GuzzleHttp\Client;

$mailchimpApiKey = 'd8d6f9975a22a99c8615b8c7af73ec5d-us13';
$mailchimpServerPrefix = 'us13'; 
$mailchimpListId = 'efbff77a21'; 

$notionToken = 'secret_mllTPJkeHOfjETPvBCe4DS6B8w9pIwbSyHy5QrBcC9Z';
$notionDatabaseId = 'b0bcab0a17994da6a37d484ce8d83d72';

$client = new Client();

try {
    // Fetch Mailchimp Audience Data
    $response = $client->request('GET', "https://$mailchimpServerPrefix.api.mailchimp.com/3.0/lists/$mailchimpListId/members", [
        'auth' => ['anystring', $mailchimpApiKey],
    ]);

    $mailchimpData = json_decode($response->getBody()->getContents(), true);
    // If there are no members in the response, exit the script
    if (empty($mailchimpData['members'])) {
        echo "No members found in the Mailchimp audience.";
        exit();
    }

    // Process each member
    foreach ($mailchimpData['members'] as $member) {

        $email = $member['email_address'];
        $firstName = $member['merge_fields']['FNAME'] ?? '';
        $lastName = $member['merge_fields']['LNAME'] ?? '';

        $checkResponse = $client->request('POST', 'https://api.notion.com/v1/databases/'.$notionDatabaseId.'/query', [
            'headers' => [
                'Authorization' => "Bearer $notionToken",
                'Content-Type' => 'application/json',
                'Notion-Version' => '2022-06-28',
            ],
            'json' => [
                'filter' => [
                    'or' => [
                        [
                            'property' => 'Email',
                            'email' => [
                                'equals' => $email,
                            ],
                        ]
                    ]
                ],
            ],
            
            
        ]);
        
        $checkData = json_decode($checkResponse->getBody()->getContents(), true);
        
        if (!empty($checkData['results'])) {
            echo "Skipped: $email already exists in Notion\n";
            continue; // Skip adding this member and move to the next one
        }

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

        // Insert data into Notion for each member
        $response = $client->request('POST', 'https://api.notion.com/v1/pages', [
            'headers' => [
                'Authorization' => "Bearer $notionToken",
                'Content-Type' => 'application/json',
                'Notion-Version' => '2022-06-28',
            ],
            'body' => json_encode($notionData),
        ]);

        if ($response->getStatusCode() == 200) {
            echo "Success: Added $email to Notion\n";
        } else {
            echo "Error: Could not add $email to Notion\n";
        }
    }
} catch (Exception $e) {
    if ($e instanceof \GuzzleHttp\Exception\ClientException) {
        $responseBody = $e->getResponse()->getBody()->getContents();
        echo 'Caught exception: ', $responseBody, "\n";
    } else {
        echo 'Caught exception: ', $e->getMessage(), "\n";
    }
}

?>
