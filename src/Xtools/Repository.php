<?php
/**
 * This file contains only the Repository class.
 */

namespace Xtools;

use Doctrine\DBAL\Connection;
use Mediawiki\Api\MediawikiApi;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * A repository is responsible for retrieving data from wherever it lives (databases, APIs,
 * filesystems, etc.)
 */
abstract class Repository
{

    /** @var Container The application's DI container. */
    protected $container;

    /** @var Connection The database connection to the meta database. */
    private $metaConnection;

    /** @var Connection The database connection to the projects' databases. */
    private $projectsConnection;

    /** @var Connection The database connection to other tools' databases. */
    private $toolsConnection;

    /** @var Connection The database connection to the temporary database. */
    private $temporaryConnection;

    /** @var CacheItemPoolInterface The cache. */
    protected $cache;

    /** @var LoggerInterface The log. */
    protected $log;

    /** @var Stopwatch The stopwatch for time profiling. */
    protected $stopwatch;

    /**
     * Create a new Repository with nothing but a null-logger.
     */
    public function __construct()
    {
        $this->log = new NullLogger();
    }

    /**
     * Set the DI container.
     * @param Container $container
     */
    public function setContainer(Container $container)
    {
        $this->container = $container;
        $this->cache = $container->get('cache.app');
        $this->log = $container->get('logger');
        $this->stopwatch = $container->get('debug.stopwatch');
    }

    /**
     * Get the database connection for the 'meta' database.
     * @return Connection
     */
    protected function getMetaConnection()
    {
        if (!$this->metaConnection instanceof Connection) {
            $this->metaConnection = $this->container
                ->get('doctrine')
                ->getManager('meta')
                ->getConnection();
        }
        return $this->metaConnection;
    }

    /**
     * Get the database connection for the 'projects' database.
     * @return Connection
     */
    protected function getProjectsConnection()
    {
        if (!$this->projectsConnection instanceof Connection) {
            $this->projectsConnection = $this->container
                ->get('doctrine')
                ->getManager('replicas')
                ->getConnection();
        }
        return $this->projectsConnection;
    }

    /**
     * Get the database connection for the 'tools' database
     * (the one that other tools store data in).
     * @return Connection
     */
    protected function getToolsConnection()
    {
        if (!$this->toolsConnection instanceof Connection) {
            $this->toolsConnection = $this->container
                ->get('doctrine')
                ->getManager('toolsdb')
                ->getConnection();
        }
        return $this->toolsConnection;
    }

    /**
     * Get the database connection for the 'temporary' database.
     * @return Connection
     */
    protected function getTemporaryConnection()
    {
        if (!$this->temporaryConnection instanceof Connection) {
            $this->temporaryConnection = $this->container
                ->get('doctrine')
                ->getManager('temporary')
                ->getConnection();
        }
        return $this->temporaryConnection;
    }

    /**
     * Get the API object for the given project.
     *
     * @param Project $project
     * @return MediawikiApi
     */
    public function getMediawikiApi(Project $project)
    {
        $apiPath = $this->container->getParameter('api_path');
        if ($apiPath) {
            $api = MediawikiApi::newFromApiEndpoint($project->getUrl().$apiPath);
        } else {
            $api = MediawikiApi::newFromPage($project->getUrl());
        }
        return $api;
    }

    /**
     * Is XTools connecting to MMF Labs?
     * @return boolean
     */
    public function isLabs()
    {
        return (bool)$this->container->getParameter('app.is_labs');
    }

    /**
     * Normalize and quote a table name for use in SQL.
     *
     * @param string $databaseName
     * @param string $tableName
     * @param string|null [$tableExtension] Optional table extension, which will only get used if we're on labs.
     * @return string Fully-qualified and quoted table name.
     */
    public function getTableName($databaseName, $tableName, $tableExtension = null)
    {
        $mapped = false;

        // This is a workaround for a one-to-many mapping
        // as required by Labs. We combine $tableName with
        // $tableExtension in order to generate the new table name
        if ($this->isLabs() && $tableExtension !== null) {
            $mapped = true;
            $tableName = $tableName . '_' . $tableExtension;
        } elseif ($this->container->hasParameter("app.table.$tableName")) {
            // Use the table specified in the table mapping configuration, if present.
            $mapped = true;
            $tableName = $this->container->getParameter("app.table.$tableName");
        }

        // For 'revision' and 'logging' tables (actually views) on Labs, use the indexed versions
        // (that have some rows hidden, e.g. for revdeleted users).
        // This is a safeguard in case table mapping isn't properly set up.
        $isLoggingOrRevision = in_array($tableName, ['revision', 'logging', 'archive']);
        if (!$mapped && $isLoggingOrRevision && $this->isLabs()) {
            $tableName = $tableName."_userindex";
        }

        // Figure out database name.
        // Use class variable for the database name if not set via function parameter.
        if ($this->isLabs() && substr($databaseName, -2) != '_p') {
            // Append '_p' if this is labs.
            $databaseName .= '_p';
        }

        return "`$databaseName`.`$tableName`";
    }
}
