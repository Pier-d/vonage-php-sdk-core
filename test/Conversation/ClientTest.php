<?php

declare(strict_types=1);

namespace VonageTest\Conversation;

use Laminas\Diactoros\Request;
use Laminas\Diactoros\Response;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Vonage\Client;
use Vonage\Client\APIResource;
use Vonage\Conversation\Client as ConversationClient;
use Vonage\Conversation\ConversationObjects\Conversation;
use Vonage\Conversation\ConversationObjects\ConversationCallback;
use Vonage\Conversation\ConversationObjects\ConversationNumber;
use Vonage\Conversation\ConversationObjects\CreateConversationRequest;
use Vonage\Conversation\ConversationObjects\UpdateConversationRequest;
use Vonage\Conversation\Filter\ListConversationFilter;
use Vonage\Conversation\Filter\ListUserConversationsFilter;
use Vonage\Entity\IterableAPICollection;
use VonageTest\Psr7AssertionTrait;
use VonageTest\VonageTestCase;

class ClientTest extends VonageTestCase
{
    use Psr7AssertionTrait;

    protected ObjectProphecy $vonageClient;
    protected ConversationClient $conversationsClient;
    protected APIResource $api;
    protected int $requestIndex = 0;

    public function setUp(): void
    {
        $this->vonageClient = $this->prophesize(Client::class);
        $this->vonageClient->getRestUrl()->willReturn('https://api.nexmo.com');
        $this->vonageClient->getCredentials()->willReturn(
            new Client\Credentials\Container(new Client\Credentials\Keypair(
                file_get_contents(__DIR__ . '/../Client/Credentials/test.key'),
                'def'
            ))
        );

        /** @noinspection PhpParamsInspection */
        $this->api = (new APIResource())
            ->setIsHAL(true)
            ->setCollectionName('conversations')
            ->setErrorsOn200(false)
            ->setClient($this->vonageClient->reveal())
            ->setAuthHandler(new Client\Credentials\Handler\KeypairHandler())
            ->setBaseUrl('https://api.nexmo.com/v1/conversations');

        $this->conversationsClient = new ConversationClient($this->api);
    }

    public function testHasSetupClientCorrectly(): void
    {
        $this->assertInstanceOf(ConversationClient::class, $this->conversationsClient);
    }

    public function testWillUseCorrectAuth(): void
    {
        $this->vonageClient->send(Argument::that(function (Request $request) {
            $this->assertEquals(
                'Bearer ',
                mb_substr($request->getHeaders()['Authorization'][0], 0, 7)
            );

            return true;
        }))->willReturn($this->getResponse('list-conversations'));

        $this->conversationsClient->listConversations();
        $this->assertTrue(true);
    }

    public function testWillListConversations(): void
    {
        $this->vonageClient->send(Argument::that(function (Request $request) use (&$requestIndex) {
            $this->requestIndex++;
            $this->assertEquals('GET', $request->getMethod());

            $uri = $request->getUri();
            $uriString = $uri->__toString();

            if ($requestIndex == 1) {
                $this->assertEquals('https://api.nexmo.com/v1/conversations', $uriString);
            }

            if ($requestIndex == 2) {
                $this->assertEquals('https://api.nexmo.com/v1/conversations?order=desc&page_size=10&cursor=7EjDNQrAcipmOnc0HCzpQRkhBULzY44ljGUX4lXKyUIVfiZay5pv9wg=');
            }

            return true;
        }))->willReturn($this->getResponse('list-conversations'));

        $response = $this->conversationsClient->listConversations();
        $this->assertInstanceOf(IterableAPICollection::class, $response);

        $conversations = [];

        foreach ($response as $conversation) {
            $conversations[] = $conversation;
        }

        $this->assertInstanceOf(Conversation::class, $conversations[0]);

        $conversationEntity = $conversations[0];

        $expectedEntityValues = [
            'id' => 'CON-d66d47de-5bcb-4300-94f0-0c9d4b948e9a',
            'name' => 'customer_chat',
            'display_name' => 'Customer Chat',
            'image_url' => 'https://example.com/image.png',
            'timestamp' => [
                'created' => '2019-09-03T18:40:24.324Z',
                'updated' => '2019-09-03T18:40:24.324Z',
                'destroyed' => '2019-09-03T18:40:24.324Z'
            ],
            '_links' => [
                'self' => [
                    'href' => 'https://api.nexmo.com/v1/conversations/CON-d66d47de-5bcb-4300-94f0-0c9d4b948e9a'
                ]
            ]
        ];

        $this->assertEquals($expectedEntityValues, $conversationEntity->toArray());

        $this->requestIndex = 0;
    }

    public function testWillListConversationsByQueryParameters(): void
    {
        $this->vonageClient->send(Argument::that(function (Request $request) use (&$requestIndex) {
            $this->assertEquals('GET', $request->getMethod());

            $uri = $request->getUri();
            $uriString = $uri->__toString();

            $this->assertEquals('https://api.nexmo.com/v1/conversations?date_start=2018-01-01+10%3A00%3A00&date_end=2018-01-01+12%3A00%3A00&page_size=5&order=asc', $uriString);

            return true;
        }))->willReturn($this->getResponse('list-conversations'));

        $filter = new ListConversationFilter();
        $filter->setStartDate('2018-01-01 10:00:00');
        $filter->setEndDate('2018-01-01 12:00:00');
        $filter->setPageSize(5);
        $filter->setOrder('asc');

        $response = $this->conversationsClient->listConversations($filter);
        $this->assertInstanceOf(IterableAPICollection::class, $response);

        $conversations = [];

        foreach ($response as $conversation) {
            $conversations[] = $conversation;
        }

        $this->assertInstanceOf(Conversation::class, $conversations[0]);
    }

    public function testWillCreateConversation(): void
    {
        $this->vonageClient->send(Argument::that(function (Request $request) use (&$requestIndex) {
            $this->assertEquals('POST', $request->getMethod());

            $uri = $request->getUri();
            $uriString = $uri->__toString();

            $this->assertEquals('https://api.nexmo.com/v1/conversations', $uriString);
            $this->assertRequestJsonBodyContains('name', 'customer_chat', $request);
            $this->assertRequestJsonBodyContains('display_name', 'Customer Chat', $request);
            $this->assertRequestJsonBodyContains('image_url', 'https://example.com/image.png', $request);
            $this->assertRequestJsonBodyContains('ttl', 60, $request, true);
            $this->assertRequestJsonBodyContains('numbers', ['type' => 'phone', 'number' => '447700900000'], $request);

            $callbackStructure = [
                'url' => 'https://example.com/eventcallback',
                'event_mask' => 'member:invited, member:joined',
                'params' => [
                    'applicationId' => 'afa393df-2c46-475b-b2d6-92da4ea05481',
                    'ncco_url' => 'https://example.com/ncco',
                ],
                'method' => 'POST'
            ];

            $this->assertRequestJsonBodyContains('callback', $callbackStructure, $request);

            return true;
        }))->willReturn($this->getResponse('create-conversation'));

        $conversation = new CreateConversationRequest('customer_chat', 'Customer Chat', 'https://example.com/image.png');
        $conversation->setTtl(60);

        $conversationNumber = new ConversationNumber('447700900000');

        $conversationCallback = new ConversationCallback('https://example.com/eventcallback');
        $conversationCallback->setEventMask('member:invited, member:joined');
        $conversationCallback->setApplicationId('afa393df-2c46-475b-b2d6-92da4ea05481');
        $conversationCallback->setNccoUrl('https://example.com/ncco');

        $conversation->setNumber($conversationNumber);
        $conversation->setConversationCallback($conversationCallback);

        $response = $this->conversationsClient->createConversation($conversation);

        $conversationShape = [
            "id" => "CON-d66d47de-5bcb-4300-94f0-0c9d4b948e9a",
            "name" => "customer_chat",
            "display_name" => "Customer Chat",
            "image_url" => "https://example.com/image.png",
            "state" => "ACTIVE",
            "sequence_number" => 0,
            "timestamp" => [
                "created" => "2019-09-03T18:40:24.324Z",
                "updated" => "2019-09-03T18:50:24.324Z",
                "destroyed" => "2019-09-05T18:40:24.324Z"
            ],
            "properties" => [
                "ttl" => 60,
                "custom_data" => [
                    "property1" => "string",
                    "property2" => "string"
                ]
            ],
            "_links" => [
                "self" => [
                    "href" => "https://api.nexmo.com/v1/conversations/CON-d66d47de-5bcb-4300-94f0-0c9d4b948e9a"
                ]
            ]
        ];

        $this->assertEquals($conversationShape, $response->toArray());
    }

    public function testWillRetrieveConversation(): void
    {
        $this->vonageClient->send(Argument::that(function (Request $request) use (&$requestIndex) {
            $this->assertEquals('GET', $request->getMethod());

            $uri = $request->getUri();
            $uriString = $uri->__toString();

            $this->assertEquals('https://api.nexmo.com/v1/conversations/CON-d66d47de-5bcb-4300-94f0-0c9d4b948e9a', $uriString);

            return true;
        }))->willReturn($this->getResponse('get-conversation'));

        $response = $this->conversationsClient->getConversationById('CON-d66d47de-5bcb-4300-94f0-0c9d4b948e9a');
        $this->assertInstanceOf(Conversation::class, $response);

        $conversationShape = [
            "id" => "CON-d66d47de-5bcb-4300-94f0-0c9d4b948e9a",
            "name" => "customer_chat",
            "display_name" => "Customer Chat",
            "image_url" => "https://example.com/image.png",
            "state" => "ACTIVE",
            "sequence_number" => 0,
            "timestamp" => [
                "created" => "2019-09-03T18:40:24.324Z",
                "updated" => "2019-09-03T18:50:24.324Z",
                "destroyed" => "2019-09-05T18:40:24.324Z"
            ],
            "properties" => [
                "ttl" => 60,
                "custom_data" => [
                    "property1" => "string",
                    "property2" => "string"
                ]
            ],
            "_links" => [
                "self" => [
                    "href" => "https://api.nexmo.com/v1/conversations/CON-d66d47de-5bcb-4300-94f0-0c9d4b948e9a"
                ]
            ]
        ];

        $this->assertEquals($conversationShape, $response->toArray());
    }

    public function testWillUpdateConversation(): void
    {
        $this->vonageClient->send(Argument::that(function (Request $request) use (&$requestIndex) {
            $this->assertEquals('PUT', $request->getMethod());

            $uri = $request->getUri();
            $uriString = $uri->__toString();

            $this->assertEquals('https://api.nexmo.com/v1/conversations/CON-d66d47de-5bcb-4300-94f0-0c9d4b948e9a', $uriString);

            return true;
        }))->willReturn($this->getResponse('update-conversation'));

        $updatePayload = [
            'name' => 'customer_sausages',
            'display_name' => 'Customer Sausages'
        ];

        $updateConversationRequest = new UpdateConversationRequest(
            'CON-d66d47de-5bcb-4300-94f0-0c9d4b948e9a',
            $updatePayload
        );

        $response = $this->conversationsClient->updateConversationById('CON-d66d47de-5bcb-4300-94f0-0c9d4b948e9a', $updateConversationRequest);
        $this->assertInstanceOf(Conversation::class, $response);

        $conversationShape = [
            "id" => "CON-d66d47de-5bcb-4300-94f0-0c9d4b948e99",
            "name" => "customer_sausages",
            "display_name" => "Customer Sausages",
            "image_url" => "https://example.com/image.png",
            "state" => "ACTIVE",
            "sequence_number" => 0,
            "timestamp" => [
                "created" => "2019-09-03T18:40:24.324Z",
                "updated" => "2019-09-03T18:50:24.324Z",
                "destroyed" => "2019-09-05T18:40:24.324Z"
            ],
            "properties" => [
                "ttl" => 60,
                "custom_data" => [
                    "property1" => "string",
                    "property2" => "string"
                ]
            ],
            "_links" => [
                "self" => [
                    "href" => "https://api.nexmo.com/v1/conversations/CON-d66d47de-5bcb-4300-94f0-0c9d4b948e99"
                ]
            ]
        ];

        $this->assertEquals($conversationShape, $response->toArray());
    }

    public function testWillDeleteConversation(): void
    {
        $this->vonageClient->send(Argument::that(function (Request $request) use (&$requestIndex) {
            $this->assertEquals('DELETE', $request->getMethod());

            $uri = $request->getUri();
            $uriString = $uri->__toString();

            $this->assertEquals('https://api.nexmo.com/v1/conversations/CON-d66d47de-5bcb-4300-94f0-0c9d4b948e9a', $uriString);

            return true;
        }))->willReturn($this->getResponse('delete-conversation', 204));

        $response = $this->conversationsClient->deleteConversationById('CON-d66d47de-5bcb-4300-94f0-0c9d4b948e9a');

        $this->assertTrue($response);
    }

    public function testWillListMembersByConversationId(): void
    {
        $this->vonageClient->send(Argument::that(function (Request $request) use (&$requestIndex) {
            $this->requestIndex++;
            $this->assertEquals('GET', $request->getMethod());

            $uri = $request->getUri();
            $uriString = $uri->__toString();

            if ($requestIndex == 1) {
                $this->assertEquals('https://api.nexmo.com/v1/users/CON-d66d47de-5bcb-4300-94f0-0c9d4b948e9a/conversations?page_size=1', $uriString);
            }

            if ($requestIndex == 2) {
                $this->assertEquals('https://api.nexmo.com/v1/conversations?order=desc&page_size=10&cursor=7EjDNQrAcipmOnc0HCzpQRkhBULzY44ljGUX4lXKyUIVfiZay5pv9wg=');
            }

            return true;
        }))->willReturn($this->getResponse('list-user-conversations'), $this->getResponse('list-user-conversations-2'));

        $response = $this->conversationsClient->listUserConversationsByUserId('CON-d66d47de-5bcb-4300-94f0-0c9d4b948e9a');
        $this->assertInstanceOf(IterableAPICollection::class, $response);

        $conversations = [];

        foreach ($response as $conversation) {
            $conversations[] = $conversation;
        }

        $this->assertInstanceOf(Conversation::class, $conversations[0]);
        $this->assertCount(2, $conversations);

        $conversationEntity = $conversations[0];

        $expectedEntityValues = [
            "id" => "CON-d66d47de-5bcb-4300-94f0-0c9d4b948e9a",
            "name" => "customer_chat",
            "display_name" => "Customer Chat",
            "image_url" => "https://example.com/image.png",
            "sequence_number" => 0,
            "properties" => [
                "ttl" => 60,
                "custom_data" => [
                    "property1" => "list-user-conversations",
                    "property2" => "string"
                ]
            ],
            "timestamp" => [
                "created" => "2019-09-03T18:40:24.324Z",
                "updated" => "2019-09-03T18:50:24.324Z",
                "destroyed" => "2019-09-05T18:40:24.324Z"
            ],
            "_links" => [
                "self" => [
                    "href" => "https://api.nexmo.com/v1/conversations/CON-7f977ca5-6e86-46a8-bdc9-67b9d8c8dfa9"
                ]
            ],
            "_embedded" => [
                "id" => "MEM-63f61863-4a51-4f6b-86e1-46edebio0391",
                "state" => "JOINED"
            ]
        ];

        $this->assertEquals($expectedEntityValues, $conversationEntity->toArray());

        $this->requestIndex = 0;
    }

    public function testWillListMembersByConversationByUserIdUsingQueryParameters(): void
    {
        $this->vonageClient->send(Argument::that(function (Request $request) use (&$requestIndex) {
            $this->requestIndex++;
            $this->assertEquals('GET', $request->getMethod());

            $uri = $request->getUri();
            $uriString = $uri->__toString();

            if ($requestIndex == 1) {
                $this->assertEquals('https://api.nexmo.com/v1/users/CON-d66d47de-5bcb-4300-94f0-0c9d4b948e9a/conversations?page_size=sausages', $uriString);
            }

            if ($requestIndex == 2) {
                $this->assertEquals('https://api.nexmo.com/v1/conversations?order=desc&page_size=10&cursor=7EjDNQrAcipmOnc0HCzpQRkhBULzY44ljGUX4lXKyUIVfiZay5pv9wg=');
            }

            return true;
        }))->willReturn($this->getResponse('list-user-conversations'), $this->getResponse('list-user-conversations-2'));

        $filter = new ListUserConversationsFilter();
        $filter->setState('INVITED');
        $filter->setIncludeCustomData(true);
        $filter->setOrderBy('created');
        $filter->setStartDate('2018-01-01 10:00:00');
        $filter->setEndDate('2018-01-01 12:00:00');
        $filter->setPageSize(5);
        $filter->setOrder('asc');

        $response = $this->conversationsClient->listUserConversationsByUserId('CON-d66d47de-5bcb-4300-94f0-0c9d4b948e9a', $filter);

        foreach ($response as $conversation) {
            $conversations[] = $conversation;
        }

        $this->requestIndex = 0;
    }

    public function testWillCreateMemberInConversation(): void
    {
        $this->markTestIncomplete();
    }

    public function testWillGetMeAsMemberInConversation(): void
    {
        $this->markTestIncomplete();
    }

    public function testWillGetMemberInConversation(): void
    {
        $this->markTestIncomplete();
    }

    public function testWillUpdateMemberInConversation(): void
    {
        $this->markTestIncomplete();
    }

    public function testWillCreateEventInConversation(): void
    {
        $this->markTestIncomplete();
    }

    public function testWillGetEventsFromConversation(): void
    {
        $this->markTestIncomplete();
    }

    public function testWillGetEventFromConversation(): void
    {
        $this->markTestIncomplete();
    }

    public function testWillDeleteEventFromConversation(): void
    {
        $this->markTestIncomplete();
    }

    public function testWillGetUserConversations(): void
    {
        $this->markTestIncomplete();
    }

    public function testWillListUserSessions(): void
    {
        $this->markTestIncomplete();
    }

    protected function getResponse(string $identifier, int $status = 200): Response
    {
        return new Response(fopen(__DIR__ . '/Fixtures/Responses/' . $identifier . '.json', 'rb'), $status);
    }
}
