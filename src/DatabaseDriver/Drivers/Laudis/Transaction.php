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
     *
     * @return Node
     */
    public function makeNode()
    {
        if ($this->client) {
            return $this->client->makeNode();
        }
        throw new \RuntimeException('Client not available in transaction context');
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
     *
     * @return Relation
     */
    public function makeRelationship()
    {
        if ($this->client) {
            return $this->client->makeRelationship();
        }
        throw new \RuntimeException('Client not available in transaction context');
    }

    /**
     * Get a node by ID.
     *
     * @param int $id
     * @return Node
     */
    public function getNode($id)
    {
        if ($this->client) {
            return $this->client->getNode($id);
        }
        throw new \RuntimeException('Client not available in transaction context');
    }

    /**
     * Delete a node.
     *
     * @param NodeInterface $node
     * @return void
     */
    public function deleteNode(NodeInterface $node)
    {
        if ($this->client) {
            $this->client->deleteNode($node);
        } else {
            throw new \RuntimeException('Client not available in transaction context');
        }
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
}
