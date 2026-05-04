<?php

namespace Cloudflare\APO\API\Test;

use Cloudflare\APO\API\Client;
use Cloudflare\APO\Integration\DefaultIntegration;

class ClientTest extends \PHPUnit\Framework\TestCase
{
    private $mockConfig;
    private $mockClientAPI;
    private $mockAPI;
    private $mockDataStore;
    private $mockLogger;
    private $mockCpanelIntegration;

    public function setup(): void
    {
        $this->mockConfig = $this->getMockBuilder('Cloudflare\APO\Integration\DefaultConfig')
            ->disableOriginalConstructor()
            ->getMock();
        $this->mockAPI = $this->getMockBuilder('Cloudflare\APO\Integration\IntegrationAPIInterface')
            ->getMock();
        $this->mockDataStore = $this->getMockBuilder('Cloudflare\APO\Integration\DataStoreInterface')
            ->disableOriginalConstructor()
            ->getMock();
        $this->mockLogger = $this->getMockBuilder('Cloudflare\APO\Integration\DefaultLogger')
            ->disableOriginalConstructor()
            ->getMock();
        $this->mockCpanelIntegration = new DefaultIntegration($this->mockConfig, $this->mockAPI, $this->mockDataStore, $this->mockLogger);

        $this->mockClientAPI = new Client($this->mockCpanelIntegration);
    }

    public function testBeforeSendAddsRequestHeaders()
    {
        // nosemgrep: generic.secrets.security.detected-generic-api-key.detected-generic-api-key
        $apiKey = '41db178adf2ef1c82c84db6ca455457646d33';
        $email = 'test@email.com';

        $this->mockDataStore->method('getClientV4APIKey')->willReturn($apiKey);
        $this->mockDataStore->method('getCloudFlareEmail')->willReturn($email);

        $request = new \Cloudflare\APO\API\Request(null, null, null, null);
        $beforeSendRequest = $this->mockClientAPI->beforeSend($request);

        $actualRequestHeaders = $beforeSendRequest->getHeaders();
        $expectedRequestHeaders = array(
            Client::X_AUTH_KEY => $apiKey,
            Client::X_AUTH_EMAIL => $email,
            Client::CONTENT_TYPE_KEY => Client::APPLICATION_JSON_KEY,
        );

        $this->assertEquals($expectedRequestHeaders[Client::X_AUTH_KEY], $actualRequestHeaders[Client::X_AUTH_KEY]);
        $this->assertEquals($expectedRequestHeaders[Client::X_AUTH_EMAIL], $actualRequestHeaders[Client::X_AUTH_EMAIL]);
        $this->assertEquals($expectedRequestHeaders[Client::CONTENT_TYPE_KEY], $actualRequestHeaders[Client::CONTENT_TYPE_KEY]);
    }

    public function testBeforeSendUsesGlobalKeyHeadersForCfkPrefix()
    {
        // New-format Global API Key: "cfk_" + 40 chars + checksum.
        // nosemgrep: generic.secrets.security.detected-generic-api-key.detected-generic-api-key
        $apiKey = 'cfk_' . str_repeat('a', 40) . 'X1y2';
        $email = 'test@email.com';

        $this->mockDataStore->method('getClientV4APIKey')->willReturn($apiKey);
        $this->mockDataStore->method('getCloudFlareEmail')->willReturn($email);

        $request = new \Cloudflare\APO\API\Request(null, null, null, null);
        $beforeSendRequest = $this->mockClientAPI->beforeSend($request);

        $headers = $beforeSendRequest->getHeaders();

        $this->assertSame($apiKey, $headers[Client::X_AUTH_KEY]);
        $this->assertSame($email, $headers[Client::X_AUTH_EMAIL]);
        $this->assertArrayNotHasKey(Client::AUTHORIZATION, $headers);
    }

    public function testBeforeSendUsesBearerAuthForApiToken()
    {
        // Pre-2026 API Token format: 40-char alphanumeric (mixed case).
        // nosemgrep: generic.secrets.security.detected-generic-api-key.detected-generic-api-key
        $apiKey = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMN';
        $email = 'test@email.com';

        $this->mockDataStore->method('getClientV4APIKey')->willReturn($apiKey);
        $this->mockDataStore->method('getCloudFlareEmail')->willReturn($email);

        $request = new \Cloudflare\APO\API\Request(null, null, null, null);
        $beforeSendRequest = $this->mockClientAPI->beforeSend($request);

        $headers = $beforeSendRequest->getHeaders();

        $this->assertSame("Bearer {$apiKey}", $headers[Client::AUTHORIZATION]);
        $this->assertArrayNotHasKey(Client::X_AUTH_KEY, $headers);
        $this->assertArrayNotHasKey(Client::X_AUTH_EMAIL, $headers);
    }

    /**
     * @dataProvider providerIsGlobalApiKey
     */
    public function testIsGlobalApiKey($key, $expected, $description)
    {
        $this->assertSame(
            $expected,
            Client::isGlobalApiKey($key),
            $description
        );
    }

    public function providerIsGlobalApiKey()
    {
        return array(
            // New scannable Global API Key format.
            'new cfk_ Global API Key' => array(
                'cfk_' . str_repeat('a', 40) . 'X1y2',
                true,
                'cfk_-prefixed Global API Key should be detected as Global API Key',
            ),
            // New scannable API Token formats.
            'new cfut_ User API Token' => array(
                'cfut_' . str_repeat('a', 40) . 'X1y2',
                false,
                'cfut_-prefixed User API Token should be sent as Bearer',
            ),
            'new cfat_ Account API Token' => array(
                'cfat_' . str_repeat('b', 40) . 'X1y2',
                false,
                'cfat_-prefixed Account API Token should be sent as Bearer',
            ),
            // Pre-2026 Global API Key format: 37-45 lowercase hex characters.
            'pre-2026 Global API Key (37 chars hex)' => array(
                str_repeat('a', 37),
                true,
                '37-char lowercase hex matches legacy Global API Key format',
            ),
            'pre-2026 Global API Key (40 chars hex)' => array(
                str_repeat('a', 40),
                true,
                '40-char lowercase hex matches legacy Global API Key format',
            ),
            'pre-2026 Global API Key (45 chars hex)' => array(
                str_repeat('a', 45),
                true,
                '45-char lowercase hex matches legacy Global API Key format',
            ),
            'lowercase hex outside 37-45 range' => array(
                str_repeat('a', 48),
                false,
                'Hex strings outside the 37-45 range are not Global API Keys',
            ),
            // Pre-2026 API Token format: 40-char alphanumeric, mixed case.
            'pre-2026 API Token (40 char mixed case)' => array(
                'abcdefghijklmnopqrstuvwxyz0123456789ABCD',
                false,
                '40-char alphanumeric (mixed case) is an API Token',
            ),
            // Defensive cases.
            'empty string' => array(
                '',
                false,
                'Empty credential should not be treated as Global API Key',
            ),
        );
    }

    public function testClientApiErrorReturnsValidStructure()
    {
        $expectedErrorResponse = array(
            'result' => null,
            'success' => false,
            'errors' => array(
                array(
                    'code' => '',
                    'message' => 'Test Message',
                ),
            ),
            'messages' => array(),
        );
        $errorResponse = $this->mockClientAPI->createAPIError('Test Message');
        $this->assertEquals($errorResponse, $expectedErrorResponse);
    }

    public function testResponseOkReturnsTrueForValidResponse()
    {
        $v4APIResponse = array(
            'success' => true,
        );

        $this->assertTrue($this->mockClientAPI->responseOk($v4APIResponse));
    }

    public function testGetErrorMessageSuccess()
    {
        $errorMessage = 'I am an error message';

        $error = $this->getMockBuilder('\Guzzle\Http\Exception\BadResponseException')
            ->disableOriginalConstructor()
            ->setMethods(array('getResponse', 'getBody', 'getMessage'))
            ->getMock();

        $errorJSON = json_encode(
            array(
                'success' => false,
                'errors' => array(
                    array(
                        'message' => $errorMessage,
                    ),
                ),
            )
        );

        $error->expects($this->any())
            ->method('getMessage')
            ->will($this->returnValue('Not this message'));
        $error->expects($this->any())
            ->method('getResponse')
            ->will($this->returnSelf());
        $error->expects($this->any())
            ->method('getBody')
            ->will($this->returnValue($errorJSON));

        $this->assertEquals($errorMessage, $this->mockClientAPI->getErrorMessage($error));
    }
}
