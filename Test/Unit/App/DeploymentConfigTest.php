<?php
declare(strict_types=1);

namespace Furan\ReadWriteSplit\Test\Unit\App;

use Furan\ReadWriteSplit\App\DeploymentConfig;
use Magento\Framework\App\DeploymentConfig\Reader;
use Magento\Framework\Config\ConfigOptionsListConstants;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for DeploymentConfig
 */
class DeploymentConfigTest extends TestCase
{
    /**
     * @var ObjectManager
     */
    private $objectManager;

    /**
     * @var Reader|MockObject
     */
    private $readerMock;

    /**
     * @var DeploymentConfig
     */
    private $deploymentConfig;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);
        $this->readerMock = $this->createMock(Reader::class);
    }

    /**
     * Test get() method returns reader_connections configuration for database connections
     *
     * @return void
     */
    public function testGetAddsReaderConnectionsToConfig(): void
    {
        $connectionKey = ConfigOptionsListConstants::CONFIG_PATH_DB_CONNECTIONS . '/default';

        $mainConfig = [
            'host' => 'master-host',
            'dbname' => 'master-db',
            'username' => 'master-user',
            'password' => 'master-pass',
        ];

        $readerConfig = [
            [
                'host' => 'reader1-host',
                'dbname' => 'reader-db',
                'username' => 'reader-user',
                'password' => 'reader-pass',
                'active' => '1',
            ],
            [
                'host' => 'reader2-host',
                'dbname' => 'reader-db',
                'username' => 'reader-user',
                'password' => 'reader-pass',
                'active' => '1',
            ]
        ];

        $this->readerMock->expects($this->once())
            ->method('load')
            ->willReturn([
                'db' => [
                    'connection' => [
                        'default' => $mainConfig
                    ],
                    'reader_connections' => [
                        'default' => $readerConfig
                    ]
                ]
            ]);

        $this->deploymentConfig = $this->objectManager->getObject(
            DeploymentConfig::class,
            [
                'reader' => $this->readerMock,
            ]
        );

        $result = $this->deploymentConfig->get($connectionKey);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('reader_connections', $result);
        $this->assertEquals($readerConfig, $result['reader_connections']);
        $this->assertEquals($mainConfig['host'], $result['host']);
    }

    /**
     * Test get() method with non-connection key returns parent value
     *
     * @return void
     */
    public function testGetWithNonConnectionKeyReturnsParentValue(): void
    {
        $key = 'some/other/config/path';
        $value = 'some-value';

        $this->readerMock->expects($this->once())
            ->method('load')
            ->willReturn([
                'some' => [
                    'other' => [
                        'config' => [
                            'path' => $value
                        ]
                    ]
                ]
            ]);

        $this->deploymentConfig = $this->objectManager->getObject(
            DeploymentConfig::class,
            [
                'reader' => $this->readerMock,
            ]
        );

        $result = $this->deploymentConfig->get($key);

        $this->assertEquals($value, $result);
    }

    /**
     * Test get() method without reader_connections configuration
     *
     * @return void
     */
    public function testGetWithoutReaderConnectionsConfiguration(): void
    {
        $connectionKey = ConfigOptionsListConstants::CONFIG_PATH_DB_CONNECTIONS . '/default';

        $mainConfig = [
            'host' => 'master-host',
            'dbname' => 'master-db',
        ];

        $this->readerMock->expects($this->once())
            ->method('load')
            ->willReturn([
                'db' => [
                    'connection' => [
                        'default' => $mainConfig
                    ]
                ]
            ]);

        $this->deploymentConfig = $this->objectManager->getObject(
            DeploymentConfig::class,
            [
                'reader' => $this->readerMock,
            ]
        );

        $result = $this->deploymentConfig->get($connectionKey);

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('reader_connections', $result);
        $this->assertEquals($mainConfig['host'], $result['host']);
    }

    /**
     * Test get() method never adds reader_connections to indexer connection
     *
     * @return void
     */
    public function testGetDoesNotAddReaderConnectionsToIndexer(): void
    {
        $connectionKey = ConfigOptionsListConstants::CONFIG_PATH_DB_CONNECTIONS . '/indexer';

        $indexerConfig = [
            'host' => 'indexer-host',
            'dbname' => 'indexer-db',
        ];

        $readerConfig = [
            [
                'host' => 'reader-host',
                'dbname' => 'reader-db',
            ]
        ];

        $this->readerMock->expects($this->once())
            ->method('load')
            ->willReturn([
                'db' => [
                    'connection' => [
                        'indexer' => $indexerConfig
                    ],
                    'reader_connections' => [
                        'indexer' => $readerConfig
                    ]
                ]
            ]);

        $this->deploymentConfig = $this->objectManager->getObject(
            DeploymentConfig::class,
            [
                'reader' => $this->readerMock,
            ]
        );

        $result = $this->deploymentConfig->get($connectionKey);

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('reader_connections', $result);
        $this->assertEquals($indexerConfig['host'], $result['host']);
    }

    /**
     * Test get() method returns default value when key doesn't exist
     *
     * @return void
     */
    public function testGetReturnsDefaultValueWhenKeyDoesNotExist(): void
    {
        $key = 'non/existent/key';
        $defaultValue = 'default-value';

        $this->readerMock->expects($this->once())
            ->method('load')
            ->willReturn([
                'db' => [
                    'connection' => [
                        'default' => []
                    ]
                ]
            ]);

        $this->deploymentConfig = $this->objectManager->getObject(
            DeploymentConfig::class,
            [
                'reader' => $this->readerMock,
            ]
        );

        $result = $this->deploymentConfig->get($key, $defaultValue);

        $this->assertEquals($defaultValue, $result);
    }

    /**
     * Test get() method with null key returns all configuration
     *
     * @return void
     */
    public function testGetWithNullKeyReturnsAllConfiguration(): void
    {
        $configData = [
            'db' => [
                'connection' => [
                    'default' => ['host' => 'master-host']
                ],
                'reader_connections' => [
                    'default' => [['host' => 'reader-host']]
                ]
            ]
        ];

        $this->readerMock->expects($this->once())
            ->method('load')
            ->willReturn($configData);

        $this->deploymentConfig = $this->objectManager->getObject(
            DeploymentConfig::class,
            [
                'reader' => $this->readerMock,
            ]
        );

        $result = $this->deploymentConfig->get(null);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('db', $result);
    }

    /**
     * Test get() method with custom connection name
     *
     * @return void
     */
    public function testGetWithCustomConnectionName(): void
    {
        $connectionKey = ConfigOptionsListConstants::CONFIG_PATH_DB_CONNECTIONS . '/custom';

        $customConfig = [
            'host' => 'custom-host',
            'dbname' => 'custom-db',
        ];

        $readerConfig = [
            [
                'host' => 'custom-reader-host',
                'dbname' => 'custom-reader-db',
            ]
        ];

        $this->readerMock->expects($this->once())
            ->method('load')
            ->willReturn([
                'db' => [
                    'connection' => [
                        'custom' => $customConfig
                    ],
                    'reader_connections' => [
                        'custom' => $readerConfig
                    ]
                ]
            ]);

        $this->deploymentConfig = $this->objectManager->getObject(
            DeploymentConfig::class,
            [
                'reader' => $this->readerMock,
            ]
        );

        $result = $this->deploymentConfig->get($connectionKey);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('reader_connections', $result);
        $this->assertEquals($readerConfig, $result['reader_connections']);
        $this->assertEquals($customConfig['host'], $result['host']);
    }

    /**
     * Test get() method with empty reader_connections array
     *
     * @return void
     */
    public function testGetWithEmptyReaderConnectionsArray(): void
    {
        $connectionKey = ConfigOptionsListConstants::CONFIG_PATH_DB_CONNECTIONS . '/default';

        $mainConfig = [
            'host' => 'master-host',
            'dbname' => 'master-db',
        ];

        $this->readerMock->expects($this->once())
            ->method('load')
            ->willReturn([
                'db' => [
                    'connection' => [
                        'default' => $mainConfig
                    ],
                    'reader_connections' => [
                        'default' => []
                    ]
                ]
            ]);

        $this->deploymentConfig = $this->objectManager->getObject(
            DeploymentConfig::class,
            [
                'reader' => $this->readerMock,
            ]
        );

        $result = $this->deploymentConfig->get($connectionKey);

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('reader_connections', $result);
    }
}
