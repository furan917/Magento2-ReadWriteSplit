<?php
declare(strict_types=1);

namespace Furan\ReadWriteSplit\App;

use Magento\Framework\App\DeploymentConfig\Reader;
use Magento\Framework\Config\ConfigOptionsListConstants;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\RuntimeException;

class DeploymentConfig extends \Magento\Framework\App\DeploymentConfig
{
    /**
     * @var array|null Array for reader config.
     */
    private $readerConfig = [];

    /**
     * Constructor
     *
     * @param Reader $reader
     * @param array $overrideData
     */
    public function __construct(
        Reader $reader,
        $overrideData = []
    ) {
        parent::__construct($reader, $overrideData);
    }

    /**
     * Gets data from flattened data
     *
     * @param null $key
     * @param mixed $defaultValue
     * @return array|null
     * @throws FileSystemException
     * @throws RuntimeException
     */
    public function get($key = null, $defaultValue = null)
    {
        $rule = '/^' . preg_quote(ConfigOptionsListConstants::CONFIG_PATH_DB_CONNECTIONS, '/') . '\/[^\/]+$/';
        if (preg_match($rule, (string)$key)) {
            $config = parent::get($key, $defaultValue);

            $keyParts = explode('/', (string)$key);
            $connectionName = $keyParts[2] ?? 'default';

            // Guard clause: Never split indexer connections
            if ($connectionName === 'indexer') {
                return $config;
            }

            $this->readerConfig = parent::get('db/reader_connections/' . $connectionName, []);

            if (!empty($this->readerConfig) && is_array($config)) {
                $config['reader_connections'] = $this->readerConfig;
            }

            return $config;
        }

        return parent::get($key, $defaultValue);
    }
}
