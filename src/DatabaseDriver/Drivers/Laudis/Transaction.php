<?php

namespace Vinelab\NeoEloquent\DatabaseDriver\Drivers\Laudis;

use Laudis\Neo4j\Contracts\UnmanagedTransactionInterface;
use Vinelab\NeoEloquent\DatabaseDriver\CypherQuery;
use Vinelab\NeoEloquent\DatabaseDriver\Interfaces\ClientInterface;
use Vinelab\NeoEloquent\DatabaseDriver\Interfaces\NodeInterface;
use Vinelab\NeoEloquent\DatabaseDriver\Interfaces\TransactionInterface;
use Laudis\Neo4j\Databags\Statement;

class Transaction implements TransactionInterface, ClientInterface
{
    /**
     * The underlying Laudis transaction instance.
     *
     * @var UnmanagedTransactionInterface
     */
    protected $transaction;

    /**
     * The parent client for operations that don't run in transaction context.
     *
     * @var Laudis
     */
    protected $client;

    /**
     * Create a new transaction instance.
     *
     * @param UnmanagedTransactionInterface $transaction
     * @param Laudis|null $client
     */
    public function __construct(UnmanagedTransactionInterface $transaction, Laudis $client = null)
    {
        $this->transaction = $transaction;
        $this->client = $client;
    }

    /**
     * Commit the transaction.
     *
     * @return void
     */
    public function commit()
    {
        if (!$this->transaction->isFinished()) {
            $this->transaction->commit();
        }
    }

    /**
     * Roll back the transaction.
     *
     * @return void
     */
    public function rollBack()
    {
        if (!$this->transaction->isFinished()) {
            $this->transaction->rollback();
        }
    }

    /**
     * Execute a Cypher query within this transaction.
     *
     * @param string $query
     * @param array $bindings
     * @return mixed
     */
    public function run($query, array $bindings = [])
    {
        $statement = new Statement($query, $bindings);
        return $this->transaction->runStatement($statement);
    }

    /**
     * Execute a Cypher query within this transaction.
     *
     * @param CypherQuery $cypherQuery
     * @return ResultSet
     */
    public function executeCypherQuery(CypherQuery $cypherQuery): ResultSet
    {
        $statement = new Statement($cypherQuery->getQuery(), $cypherQuery->getParameters());
        $result = $this->transaction->runStatement($statement);

        return new ResultSet($result);
    }

    /**
     * Make a node instance.
     * Creates the node with the underlying transaction so all
     * operations run within the transaction context.
     *
     * @return Node
     */
    public function makeNode()
    {
        return new Node($this->transaction);
    }

    /**
     * Make a label.
     *
     * @param string $label
     * @return string
     */
    public function makeLabel($label)
    {
        return $label;
    }

    /**
     * Make a relationship instance.
     * Creates the relationship with the underlying transaction so all
     * operations run within the transaction context.
     *
     * @return Relation
     */
    public function makeRelationship()
    {
        return new Relation($this->transaction);
    }

    /**
     * Get a node by ID.
     * Creates and populates the node using the transaction context.
     *
     * @param int $id
     * @return Node
     */
    public function getNode($id)
    {
        $node = $this->makeNode();
        $node->setId($id);
        $node->populateNode();

        return $node;
    }

    /**
     * Delete a node.
     * Uses the node's delete method which will use the transaction context.
     *
     * @param NodeInterface $node
     * @return void
     */
    public function deleteNode(NodeInterface $node)
    {
        $node->delete();
    }

    /**
     * Begin a new transaction (not supported within existing transaction).
     *
     * @throws \RuntimeException
     */
    public function beginTransaction()
    {
        throw new \RuntimeException('Cannot begin a transaction within an existing transaction');
    }

    /**
     * Get the underlying transaction instance.
     *
     * @return UnmanagedTransactionInterface
     */
    public function getTransaction()
    {
        return $this->transaction;
    }

    /**
     * Execute bulk Cypher queries within this transaction.
     *
     * @param array $statements
     * @return mixed
     */
    public function executeBulkCypherQuery(array $statements)
    {
        $preparedStatements = [];
        foreach ($statements as $statement) {
            $cypherQuery = new CypherQuery($this, $statement, []);
            $preparedStatements[] = new Statement($cypherQuery->getQuery(), $cypherQuery->getParameters());
        }

        return $this->transaction->runStatements($preparedStatements);
    }

    /**
     * Start a batch operation.
     * Note: Batch operations within a transaction use the transaction's context.
     *
     * @return Batch
     */
    public function startBatch()
    {
        // TODO - Batch support
        return new Batch();
    }

    /**
     * Commit a batch operation.
     * Note: Batch operations within a transaction use the transaction's context.
     *
     * @return bool
     */
    public function commitBatch()
    {
        // TODO - Batch support
        return true;
    }
}
