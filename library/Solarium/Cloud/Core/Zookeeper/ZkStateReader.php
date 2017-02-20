<?php
namespace Solarium\Cloud\Core\Zookeeper;

use Solarium\Core\Client\Endpoint;
use Solarium\Exception\InvalidArgumentException;
use Solarium\Cloud\Exception\ZookeeperException;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Zookeeper;

class ZkStateReader
{
    const BASE_URL_PROP = 'base_url';
    const NODE_NAME_PROP = 'node_name';
    const CORE_NODE_NAME_PROP = 'core_node_name';
    const ROLES_PROP = 'roles';
    const STATE_PROP = 'state';
    const CORE_NAME_PROP = 'core';
    const COLLECTION_PROP = 'collection';
    const ELECTION_NODE_PROP = 'election_node';
    const SHARD_ID_PROP = 'shard';
    const SHARDS_PROP = 'shards';
    const REPLICA_PROP = 'replica';
    const REPLICAS_PROP = 'replicas';
    const SHARD_RANGE_PROP = 'shard_range';
    const SHARD_STATE_PROP = 'shard_state';
    const SHARD_PARENT_PROP = 'shard_parent';
    const NUM_SHARDS_PROP = 'numShards';
    const LEADER_PROP = 'leader';
    const PROPERTY_PROP = 'property';
    const PROPERTY_VALUE_PROP = 'property.value';
    const MAX_AT_ONCE_PROP = 'maxAtOnce';
    const MAX_WAIT_SECONDS_PROP = 'maxWaitSeconds';
    const COLLECTIONS_ZKNODE = '/collections';
    const LIVE_NODES_ZKNODE = '/live_nodes';
    const ALIASES = '/aliases.json';
    const CLUSTER_STATE = '/clusterstate.json';
    const CLUSTER_PROPS = '/clusterprops.json';
    const COLLECTION_STATE = 'state.json';
    const REJOIN_AT_HEAD_PROP = 'rejoinAtHead';
    const SOLR_SECURITY_CONF_PATH = '/security.json';

    const REPLICATION_FACTOR = 'replicationFactor';
    const MAX_SHARDS_PER_NODE = 'maxShardsPerNode';
    const AUTO_ADD_REPLICAS = 'autoAddReplicas';
    const MAX_CORES_PER_NODE = 'maxCoresPerNode';

    const ROLES = '/roles.json';

    const CONFIGS_ZKNODE = '/configs';
    const CONFIGNAME_PROP = 'configName';

    const LEGACY_CLOUD = 'legacyCloud';

    const URL_SCHEME = 'urlScheme';

    /** @var A view of the current state of all collections; combines all the different state sources into a single view. */
    protected $clusterStates;

    const  GET_LEADER_RETRY_INTERVAL_MS = 50;
    const  GET_LEADER_RETRY_DEFAULT_TIMEOUT = 4000;
    const  LEADER_ELECT_ZKNODE = 'leader_elect';
    const  SHARD_LEADERS_ZKNODE = 'leaders';
    const  ELECTION_NODE = 'election';

    /** @var array Collections tracked in the legacy (shared) state format, reflects the contents of clusterstate.json. */
    private $legacyCollectionStates = array();
    /** Last seen ZK version of clusterstate.json. */
    protected $legacyClusterStateVersion = 0;

    /** @var  Each individual collection state combined, without the legacy clusterstate.json values */
    protected $collectionStates = array();

    /** @var  array All the live nodes */
    protected $liveNodes = array();
    /** @var  array Cluster properties from clusterproperties.json */
    protected $clusterProperties;
    protected $configManager;
    protected $securityData;
    protected $collectionWatches;

    /** @var Zookeeper  */
    protected $zkClient;
    protected $collections;
    protected $aliases;

    protected $zkTimeout = 10000;

    /**
     * ZkStateReader constructor.
     * @param string $hosts Comma separated host:port pairs, each corresponding to a zk server. e.g. "127.0.0.1:2181,127.0.0.1:2182,127.0.0.1:2183"
     */
    function __construct(string $hosts, AdapterInterface $cache = null)
    {
        //TODO check cache to see if we need to connect to zookeeper

        if($cache != null)
            $zkState = $cache->getItem("solarium-cloud.zookeeper");
        if ($cache == null || !$zkState->isHit()) {
            $this->zkClient = new Zookeeper($hosts, null, $this->zkTimeout);
            $this->readZookeeper();
        }
    }

    /**
     * Reads data from Zookeeper
     */
    protected function readZookeeper()
    {
        $this->readAliases();
        $this->readCollectionList();
        $this->readClusterStates();
        $this->readSecurityData();
        $this->readLiveNodes();
    }

    /**
     *  Read aliases and write to class property
     */
    protected function readAliases()
    {
        $this->readData(self::ALIASES, $this->aliases, true, true);
    }

    /**
     *  Read collections and write to class property
     */
    protected function readCollectionList()
    {
        $this->collections = $this->getChildren(self::COLLECTIONS_ZKNODE);
    }

    /**
     * Read cluster state and write to class property
     */
    protected function readClusterStates()
    {
        //Compatibility for older versions of Solr
        $this->readData(self::CLUSTER_STATE, $this->legacyCollectionStates, true, true);

        if(is_array($this->collections)) {
            foreach ($this->collections as $i => $collection) {
                $stateFile = self::COLLECTIONS_ZKNODE . '/' . $collection . '/' . self::COLLECTION_STATE;
                if ($this->zkClient->exists($stateFile)) {
                    $this->collectionStates = array_merge($this->collectionStates, json_decode($this->zkClient->get($stateFile), true));
                }
            }
        }

        $this->clusterStates = array_merge($this->collectionStates, $this->legacyCollectionStates);
    }

    /**
     * Read cluster properties and write to class property
     */
    protected function readClusterProperties()
    {
        $this->readData(self::CLUSTER_PROPS, $this->clusterProperties, true, true);
    }

    protected function readSecurityData()
    {
        $this->readData(self::SOLR_SECURITY_CONF_PATH, $this->securityData, true, true);
    }

    protected function readLiveNodes()
    {
        $this->liveNodes = $this->getChildren(self::LIVE_NODES_ZKNODE);
    }

    protected function readData(string $location, &$property, bool $json_decode, bool $json_assoc = false)
    {
        if($this->zkClient->exists($location)) {
            $property = $json_decode ? json_decode($this->zkClient->get($location), $json_assoc) : $this->zkClient->get($location);
        }
        else {
            throw new ZookeeperException("Cannot read data from location '$location'");
        }
    }

    /**
     * @param string $location
     * @return array
     */
    protected function getChildren(string $location): array
    {
        if ($this->zkClient->exists($location)) {
            return $this->zkClient->getChildren($location);
        } else {
            throw new ZookeeperException("Cannot read data from location '$location'");
        }
        return array();
    }

    /**
     * @return array
     */
    public function getCollectionAliases(): array
    {
        if($this->aliases != null && isset($this->aliases[self::COLLECTION_PROP]))
            return $this->aliases[self::COLLECTION_PROP];
        else
            return array();
    }

    /**
     * @return array
     */
    public function getCollectionList(): array
    {
        if($this->collections != null)
            return $this->collections;
        else
            return array();
    }

    /**
     * @return array
     */
    public function getCollectionStates(): array
    {
        if($this->collectionStates != null)
            return $this->collectionStates;
        else
            return array();
    }

    /**
     * @param string $collection
     * @return array
     * @throws ZookeeperException
     */
    public function getCollectionState(string $collection): array
    {
        $collection = $this->getCollectionName($collection);
        if($this->collectionStates != null) {
            if(!isset($this->collectionStates[$collection]))
                throw new ZookeeperException("Collection '$collection' does not exist.'");
            return $this->collectionStates[$collection];
        }
        else
            return array();
    }

    /**
     * @return array
     */
    public function getClusterStates(): array
    {
        if($this->clusterStates != null)
            return $this->clusterStates;
        else
            return array();
    }

    /**
     * @return array
     */
    public function getClusterProperties(): array
    {
        if($this->clusterProperties != null)
            return $this->clusterProperties;
        else
            return array();
    }

    /**
     * @return array live nodes
     */
    public function getLiveNodes(): array
    {
        if($this->liveNodes != null)
            return $this->liveNodes;
        else
            return array();
    }

    /**
     * @param $collection
     * @return array
     * @throws ZookeeperException
     */
    public function getActiveCollectionBaseUrls($collection): array
    {
        $collection = $this->getCollectionName($collection);
        $state = $this->getCollectionState($collection);
        $replicas = array();
        if (!empty($state)) {
            foreach ($state[self::SHARDS_PROP] as $shardname => $shard) {
                foreach($shard[self::REPLICAS_PROP] as $replicaname => $replica) {
                    if (isset($replica[self::STATE_PROP]) && $replica[self::STATE_PROP] === 'active') {
                        $base_url = $replica[self::BASE_URL_PROP];
                        if(!in_array($base_url, $replicas))
                            $replicas[$replica[self::NODE_NAME_PROP].'_'.$collection] = $base_url;
                    }
                }
            }
        }
        else {
            throw new ZookeeperException("Collection '$collection' does not exist.'");
        }
        return $replicas;
    }

    /**
     * @param $collection
     * @return array
     * @throws ZookeeperException
     */
    public function getCollectionShardLeadersBaseUrl($collection): array
    {
        $collection = $this->getCollectionName($collection);
        $state = $this->getCollectionState($collection);
        $leaders = array();
        if (!empty($state)) {
            foreach ($state[self::SHARDS_PROP] as $shardname => $shard) {
                foreach($shard[self::REPLICAS_PROP] as $replicaName => $replica) {
                    if (isset($replica[self::LEADER_PROP]) && $replica[self::LEADER_PROP] === 'true') {
                        $baseUrl = $replica[self::BASE_URL_PROP];
                        if(!in_array($baseUrl, $leaders))
                            $leaders[$replica[self::NODE_NAME_PROP].'_'.$collection] = $baseUrl;
                    }
                }
            }
        }
        else {
            throw new ZookeeperException("Collection '$collection' does not exist.'");
        }
        return $leaders;
    }

    /**
     * @param $collection
     * @return array
     */
    public function getCollectionEndpoints($collection): array
    {
        $collection = $this->getCollectionName($collection);
        $endpoints = array();
        $urls = $this->getActiveCollectionBaseUrls($collection);
        foreach($urls as $id => $url) {
            $options = array();
            $options = parse_url($url);
            $options['core'] = $collection;
            $options['key'] = $id;
            $endpoints[$id] = new Endpoint($options);
        }
        return $endpoints;
    }

    /**
     * @param $collection
     * @return array
     */
    public function getCollectionShardLeadersEndpoints($collection): array
    {
        $collection = $this->getCollectionName($collection);
        $endpoint = array();
        $urls = $this->getCollectionShardLeadersBaseUrl($collection);
        foreach($urls as $id => $url) {
            $options = array();
            $options = parse_url($url);
            $options['core'] = $collection;
            $options['key'] = $id;
            $endpoints[$id] = new Endpoint($options);
        }
        return $endpoints;
    }

    /**
     * @param string $name
     * @return string
     */
    public function getCollectionName(string $name): string
    {
        if(array_search($name, $this->collections) === false) {
            $aliases = $this->getCollectionAliases();
            if (!empty($aliases)) {
                if(array_key_exists($name, $aliases)) {
                    return $aliases[$name];
                }
            }
        }
        else {
            return $name;
        }
        throw new ZookeeperException("Collection '$name' not found.");
    }

    /**
     * @param array $zkHosts
     * @param string $chroot
     */
    public static function buildZkHostString(array $zkHosts, string $chroot = "")
    {
        if (!is_array($zkHosts) || is_empty($zkHosts)) {
            throw new InvalidArgumentException("Cannot create CloudSearchClient without valid ZooKeeper host; none specified!");
        }
        $zkHostString = "";
        $lastIndexValue = count($zkHosts) - 1;
        $i = 0;
        foreach ($zkHosts as $zkHost) {
            $zkHostString .= $zkHost;
            if ($i < $lastIndexValue) {
                $zkHostString .= ',';
            }
            $i++;
        }

        if (!is_empty($chroot)) {
            if (substr($chroot, 0, 1) == '/') {
                $zkHostString .= $chroot;
            } else {
                throw new InvalidArgumentException("The chroot must start with a forward slash.");
            }
        }
    }


}