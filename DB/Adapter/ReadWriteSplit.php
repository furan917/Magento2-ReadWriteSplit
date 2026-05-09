<?php
declare(strict_types=1);

namespace Furan\ReadWriteSplit\DB\Adapter;

use Exception;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\LoggerInterface;
use Magento\Framework\DB\SelectFactory;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Stdlib\DateTime;
use Magento\Framework\Stdlib\StringUtils;

class ReadWriteSplit extends Mysql
{
    /**
     * @var array
     */
    private $readConnections = [];

    /**
     * @var int
     */
    private $currentReadIndex = 0;

    /**
     * @var array
     */
    private $readConnectionConfigs = [];

    /**
     * @var array|null
     */
    private $activeReaderIndexes = null;

    /**
     * @var bool
     */
    private $inTransaction = false;

    /**
     * @var bool
     */
    protected $isReader = false;

    /**
     * @var bool
     */
    private static $hasWrittenInRequest = false;

    /**
     * @var SerializerInterface
     */
    protected $serializer;

    /**
     * @var StringUtils
     */
    protected $stringUtils;

    /**
     * @var DateTime
     */
    protected $dateTime;

    /**
     * @var SelectFactory
     */
    protected $selectFactory;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    public function __construct(
        StringUtils $string,
        DateTime $dateTime,
        LoggerInterface $logger,
        SelectFactory $selectFactory,
        array $config = [],
        SerializerInterface $serializer = null
    ) {
        $this->stringUtils = $string;
        $this->dateTime = $dateTime;
        $this->logger = $logger;
        $this->selectFactory = $selectFactory;
        $this->serializer = $serializer;

        if (isset($config['_is_reader'])) {
            $this->isReader = (bool)$config['_is_reader'];
            unset($config['_is_reader']);
        }

        if (isset($config['reader_connections']) && is_array($config['reader_connections'])) {
            $this->readConnectionConfigs = $config['reader_connections'];
            unset($config['reader_connections']);
        }

        parent::__construct($string, $dateTime, $logger, $selectFactory, $config, $serializer);
    }

    /**
     * Begin transactions and ensure we continue using writer till finished
     */
    public function beginTransaction()
    {
        $this->inTransaction = true;
        return parent::beginTransaction();
    }

    /**
     * Commit transaction and allow read splitting again
     */
    public function commit()
    {
        $result = parent::commit();
        $this->inTransaction = false;
        return $result;
    }

    /**
     * Rollback transaction and allow read splitting again
     */
    public function rollBack()
    {
        $result = parent::rollBack();
        $this->inTransaction = false;
        return $result;
    }

    public function query($sql, $bind = [])
    {
        $shouldUseReader = !self::$hasWrittenInRequest
            && !$this->isReader
            && $this->shouldUseReadConnection($sql);

        if ($shouldUseReader) {
            return $this->executeOnReader($sql, $bind, 'query');
        }

        if (!$this->isReader) {
            self::$hasWrittenInRequest = true;
        }

        return parent::query($sql, $bind);
    }

    public function multiQuery($sql, $bind = [])
    {
        $shouldUseReader = !self::$hasWrittenInRequest
            && !$this->isReader
            && $this->shouldUseReadConnection($sql);

        if ($shouldUseReader) {
            return $this->executeOnReader($sql, $bind, 'multiQuery');
        }

        if (!$this->isReader) {
            self::$hasWrittenInRequest = true;
        }

        return parent::multiQuery($sql, $bind);
    }

    private static function isReadOnlyStatement(string $sqlLowercase): bool
    {
        return strncmp($sqlLowercase, 'select ', 7) === 0 ||
            strncmp($sqlLowercase, 'show ', 5) === 0 ||
            strncmp($sqlLowercase, 'describe ', 9) === 0 ||
            strncmp($sqlLowercase, 'explain ', 8) === 0;
    }

    private static function containsCriticalKeywords(string $sqlLowercase): bool
    {
        static $criticalKeywords = [
            'for update',
            'lock in share mode',
            'into outfile',
            'into dumpfile'
        ];

        foreach ($criticalKeywords as $keyword) {
            if (str_contains($sqlLowercase, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private static function containsWriteOperations(string $sql, string $sqlLowercase): bool
    {
        static $writeKeywords = [
            ' insert ',
            ' update ',
            ' delete ',
            ' set ',
            ' replace ',
            'for update',
            ' create ',
            ' alter ',
            ' drop ',
            ' truncate ',
            ' rename ',
            ' lock ',
            ' unlock ',
            ' load ',
            ' into ',
            ' call ',
            ' do ',
            // ' optimize ',
            // ' analyze ',
            // ' repair ',
            // ' flush ',
            // ' grant ',
            // ' revoke '
        ];

        foreach ($writeKeywords as $writeKeyword) {
            if (str_contains($sqlLowercase, $writeKeyword)) {
                return true;
            }
        }

        static $delimiterKeywords = [
            'insert', 'update', 'delete', 'set', 'replace',
            'create', 'alter', 'drop', 'truncate', 'rename',
            'lock', 'unlock', 'load',
            'call', 'do',
            // 'optimize', 'analyze', 'repair', 'flush',
            // 'grant', 'revoke'
        ];

        $activeDelimiters = [];
        if (str_contains($sql, "\n")) {
            $activeDelimiters[] = "\n";
        }
        if (str_contains($sql, ';')) {
            $activeDelimiters[] = ';';
        }
        if (str_contains($sql, "\t")) {
            $activeDelimiters[] = "\t";
        }
        if (str_contains($sql, "\r")) {
            $activeDelimiters[] = "\r\n";
        }

        foreach ($activeDelimiters as $delimiter) {
            foreach ($delimiterKeywords as $keyword) {
                if (str_contains($sqlLowercase, $delimiter . $keyword)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function shouldUseReadConnection($sql): bool
    {
        if (self::$hasWrittenInRequest) {
            return false;
        }

        if ($this->isReader || $this->inTransaction || $this->getTransactionLevel() > 0) {
            return false;
        }

        if (PHP_SAPI === 'cli') {
            return false;
        }

        $sql = trim((string)$sql);
        if ($sql === '') {
            return false;
        }

        $sqlLowercase = strtolower($sql);

        if (!self::isReadOnlyStatement($sqlLowercase)) {
            return false;
        }

        if (!str_contains($sql, ';') && !str_contains($sql, "\n") && !str_contains($sql, "\r") && !str_contains($sql, "\t")) {
            return !self::containsCriticalKeywords($sqlLowercase);
        }

        return !self::containsWriteOperations($sql, $sqlLowercase);
    }

    private function executeOnReader($sql, $bind, $method)
    {
        if (empty($this->readConnectionConfigs)) {
            return parent::$method($sql, $bind);
        }

        try {
            return $this->getReadConnection()->$method($sql, $bind);
        } catch (Exception $e) {
            $this->logger->log('WARN: ReadWriteSplit: Reader connection failed, falling back to master');
            return parent::$method($sql, $bind);
        }
    }

    /**
     * @throws Exception
     */
    private function getReadConnection()
    {
        if ($this->activeReaderIndexes === null) {
            $this->buildActiveReaderCache();
        }

        if (empty($this->activeReaderIndexes)) {
            throw new \RuntimeException('No active read connections available');
        }

        $selectedIndex = $this->activeReaderIndexes[$this->currentReadIndex % count($this->activeReaderIndexes)];
        $this->currentReadIndex++;

        if (!isset($this->readConnections[$selectedIndex])) {
            $this->readConnections[$selectedIndex] = $this->createReadConnection($selectedIndex);
        }

        return $this->readConnections[$selectedIndex];
    }

    private function buildActiveReaderCache(): void
    {
        $this->activeReaderIndexes = [];
        foreach ($this->readConnectionConfigs as $index => $config) {
            $active = $config['active'] ?? '1';
            if ($active == '1') {
                $this->activeReaderIndexes[] = $index;
            }
        }
    }

    private function createReadConnection($configIndex): ReadWriteSplit
    {
        $readConfig = $this->readConnectionConfigs[$configIndex];
        $readConfig['_is_reader'] = true;

        return new ReadWriteSplit(
            $this->stringUtils,
            $this->dateTime,
            $this->logger,
            $this->selectFactory,
            $readConfig,
            $this->serializer
        );
    }
}
