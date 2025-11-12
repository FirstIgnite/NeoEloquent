<?php

namespace Vinelab\NeoEloquent\Tests\Functional;

use Exception;
use Mockery as M;
use RuntimeException;
use Vinelab\NeoEloquent\Eloquent\Model;
use Vinelab\NeoEloquent\Eloquent\SoftDeletes;
use Vinelab\NeoEloquent\Tests\TestCase;

// Test Models
class TransactionUser extends Model
{
    protected $label = 'TransactionUser';
    protected $fillable = ['name', 'email', 'age'];

    public function posts()
    {
        return $this->hasMany(TransactionPost::class, 'POSTED');
    }

    public function comments()
    {
        return $this->hasMany(TransactionComment::class, 'COMMENTED');
    }
}

class TransactionPost extends Model
{
    protected $label = 'TransactionPost';
    protected $fillable = ['title', 'body'];

    public function author()
    {
        return $this->belongsTo(TransactionUser::class, 'POSTED');
    }

    public function comments()
    {
        return $this->hasMany(TransactionComment::class, 'COMMENT_ON');
    }
}

class TransactionComment extends Model
{
    protected $label = 'TransactionComment';
    protected $fillable = ['content'];

    public function user()
    {
        return $this->belongsTo(TransactionUser::class, 'COMMENTED');
    }

    public function post()
    {
        return $this->belongsTo(TransactionPost::class, 'COMMENT_ON');
    }
}

class TransactionSoftDeleteUser extends Model
{
    use SoftDeletes;

    protected $label = 'TransactionSoftDeleteUser';
    protected $fillable = ['name', 'email'];
    protected $dates = ['deleted_at'];
}

class TransactionTest extends TestCase
{
    protected $connection;

    public function setUp(): void
    {
        parent::setUp();

        // Create a single shared connection instance
        $this->connection = $this->getConnectionWithConfig('default');

        $resolver = M::mock('Illuminate\Database\ConnectionResolverInterface');
        $resolver->shouldReceive('connection')->andReturn($this->connection);

        TransactionUser::setConnectionResolver($resolver);
        TransactionPost::setConnectionResolver($resolver);
        TransactionComment::setConnectionResolver($resolver);
        TransactionSoftDeleteUser::setConnectionResolver($resolver);
    }

    public function tearDown(): void
    {
        M::close();

        // Clean up all test data
        TransactionUser::all()->each(function ($model) {
            $model->delete();
        });
        TransactionPost::all()->each(function ($model) {
            $model->delete();
        });
        TransactionComment::all()->each(function ($model) {
            $model->delete();
        });
        TransactionSoftDeleteUser::withTrashed()->get()->each(function ($model) {
            $model->forceDelete();
        });

        parent::tearDown();
    }

    // ==========================================
    // 1. Basic Transaction Operations
    // ==========================================

    public function testBeginTransactionIncrementsCounter()
    {
        $this->assertEquals(0, $this->connection->transactionLevel());

        $this->connection->beginTransaction();
        $this->assertEquals(1, $this->connection->transactionLevel());

        $this->connection->beginTransaction();
        $this->assertEquals(2, $this->connection->transactionLevel());

        $this->connection->rollBack();
        $this->connection->rollBack();
    }

    public function testCommitTransaction()
    {
        $connection = $this->connection;
        
        $connection->beginTransaction();
        $this->assertEquals(1, $connection->transactionLevel());
        
        $connection->commit();
        $this->assertEquals(0, $connection->transactionLevel());
    }

    public function testRollbackTransaction()
    {
        $connection = $this->connection;
        
        $connection->beginTransaction();
        $this->assertEquals(1, $connection->transactionLevel());
        
        $connection->rollBack();
        $this->assertEquals(0, $connection->transactionLevel());
    }

    public function testNestedTransactionsCounter()
    {
        $connection = $this->connection;
        
        $connection->beginTransaction();
        $this->assertEquals(1, $connection->transactionLevel());
        
        $connection->beginTransaction();
        $this->assertEquals(2, $connection->transactionLevel());
        
        $connection->beginTransaction();
        $this->assertEquals(3, $connection->transactionLevel());
        
        $connection->commit();
        $this->assertEquals(2, $connection->transactionLevel());
        
        $connection->commit();
        $this->assertEquals(1, $connection->transactionLevel());
        
        $connection->commit();
        $this->assertEquals(0, $connection->transactionLevel());
    }

    // ==========================================
    // 2. Data Persistence Tests
    // ==========================================

    public function testCommitPersistsData()
    {
        $connection = $this->connection;
        
        $connection->beginTransaction();
        
        $user = TransactionUser::create(['name' => 'John Doe', 'email' => 'john@example.com']);
        $this->assertNotNull($user->id);
        
        $connection->commit();
        
        // Verify data exists after commit
        $found = TransactionUser::find($user->id);
        $this->assertNotNull($found);
        $this->assertEquals('John Doe', $found->name);
        $this->assertEquals('john@example.com', $found->email);
    }

    public function testRollbackDiscardsData()
    {
        $connection = $this->connection;
        
        $connection->beginTransaction();
        
        $user = TransactionUser::create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);
        $userId = $user->id;
        $this->assertNotNull($userId);
        
        $connection->rollBack();
        
        // Verify data doesn't exist after rollback
        $found = TransactionUser::find($userId);
        $this->assertNull($found);
    }

    public function testMultipleOperationsCommittedTogether()
    {
        $connection = $this->connection;
        
        $connection->beginTransaction();
        
        $user1 = TransactionUser::create(['name' => 'User One', 'email' => 'one@example.com']);
        $user2 = TransactionUser::create(['name' => 'User Two', 'email' => 'two@example.com']);
        $user3 = TransactionUser::create(['name' => 'User Three', 'email' => 'three@example.com']);
        
        $connection->commit();
        
        // Verify all data exists
        $this->assertNotNull(TransactionUser::find($user1->id));
        $this->assertNotNull(TransactionUser::find($user2->id));
        $this->assertNotNull(TransactionUser::find($user3->id));
        $this->assertEquals(3, TransactionUser::count());
    }

    public function testPartialRollbackDiscardsAll()
    {
        $connection = $this->connection;
        
        $connection->beginTransaction();
        
        $user1 = TransactionUser::create(['name' => 'User One', 'email' => 'one@example.com']);
        $user2 = TransactionUser::create(['name' => 'User Two', 'email' => 'two@example.com']);
        
        $user1Id = $user1->id;
        $user2Id = $user2->id;
        
        $connection->rollBack();
        
        // Verify none of the operations persisted
        $this->assertNull(TransactionUser::find($user1Id));
        $this->assertNull(TransactionUser::find($user2Id));
        $this->assertEquals(0, TransactionUser::count());
    }

    // ==========================================
    // 3. Model CRUD in Transactions
    // ==========================================

    public function testCreateModelInTransaction()
    {
        $connection = $this->connection;
        
        $connection->beginTransaction();
        
        $user = TransactionUser::create(['name' => 'Create Test', 'email' => 'create@example.com', 'age' => 25]);
        
        $this->assertNotNull($user->id);
        $this->assertEquals('Create Test', $user->name);
        $this->assertTrue($user->exists);
        
        $connection->commit();
        
        $found = TransactionUser::find($user->id);
        $this->assertEquals('Create Test', $found->name);
        $this->assertEquals(25, $found->age);
    }

    public function testUpdateModelInTransaction()
    {
        $user = TransactionUser::create(['name' => 'Original Name', 'email' => 'original@example.com']);
        
        $connection = $this->connection;
        $connection->beginTransaction();
        
        $user->name = 'Updated Name';
        $user->email = 'updated@example.com';
        $user->save();
        
        $connection->commit();
        
        $found = TransactionUser::find($user->id);
        $this->assertEquals('Updated Name', $found->name);
        $this->assertEquals('updated@example.com', $found->email);
    }

    public function testDeleteModelInTransaction()
    {
        $user = TransactionUser::create(['name' => 'To Delete', 'email' => 'delete@example.com']);
        $userId = $user->id;
        
        $connection = $this->connection;
        $connection->beginTransaction();
        
        $user->delete();
        
        $connection->commit();
        
        $found = TransactionUser::find($userId);
        $this->assertNull($found);
    }

    public function testQueryModelsInTransaction()
    {
        $connection = $this->connection;
        
        $connection->beginTransaction();
        
        $user1 = TransactionUser::create(['name' => 'Query User 1', 'email' => 'query1@example.com']);
        $user2 = TransactionUser::create(['name' => 'Query User 2', 'email' => 'query2@example.com']);
        
        // Query within the same transaction should see uncommitted changes
        $users = TransactionUser::where('name', 'like', 'Query User%')->get();
        $this->assertCount(2, $users);
        
        $connection->rollBack();
        
        // After rollback, data shouldn't exist
        $users = TransactionUser::where('name', 'like', 'Query User%')->get();
        $this->assertCount(0, $users);
    }

    // ==========================================
    // 4. Relationship Operations
    // ==========================================

    public function testCreateRelationshipsInTransaction()
    {
        $connection = $this->connection;
        
        $connection->beginTransaction();
        
        $user = TransactionUser::create(['name' => 'Author', 'email' => 'author@example.com']);
        $post = new TransactionPost(['title' => 'First Post', 'body' => 'Post content']);
        
        $edge = $user->posts()->save($post);
        
        $this->assertNotNull($edge);
        $this->assertTrue($edge->exists());
        
        $connection->commit();
        
        $foundUser = TransactionUser::find($user->id);
        $posts = $foundUser->posts;
        
        $this->assertCount(1, $posts);
        $this->assertEquals('First Post', $posts[0]->title);
    }

    public function testRollbackRelationshipChanges()
    {
        $connection = $this->connection;
        
        $connection->beginTransaction();
        
        $user = TransactionUser::create(['name' => 'Author', 'email' => 'author@example.com']);
        $post = new TransactionPost(['title' => 'Rollback Post', 'body' => 'This should not persist']);
        
        $user->posts()->save($post);
        
        $userId = $user->id;
        
        $connection->rollBack();
        
        // Verify neither user nor post persisted
        $this->assertNull(TransactionUser::find($userId));
        $this->assertEquals(0, TransactionPost::count());
    }

    public function testMultipleRelationshipsInTransaction()
    {
        $connection = $this->connection;
        
        $connection->beginTransaction();
        
        $user = TransactionUser::create(['name' => 'Author', 'email' => 'author@example.com']);
        $post1 = new TransactionPost(['title' => 'Post 1', 'body' => 'Content 1']);
        $post2 = new TransactionPost(['title' => 'Post 2', 'body' => 'Content 2']);
        
        $user->posts()->save($post1);
        $user->posts()->save($post2);
        
        $connection->commit();
        
        $foundUser = TransactionUser::find($user->id);
        $this->assertCount(2, $foundUser->posts);
    }

    // ==========================================
    // 5. Query Builder Integration
    // ==========================================

    public function testRawQueriesInTransaction()
    {
        $connection = $this->connection;
        
        $connection->beginTransaction();
        
        $result = $connection->statement(
            'CREATE (u:TransactionUser {name: $name, email: $email}) RETURN id(u) as id',
            ['name' => 'Raw Query User', 'email' => 'raw@example.com'],
            true
        );
        
        $this->assertNotNull($result);
        
        $connection->commit();
        
        $user = TransactionUser::where('name', 'Raw Query User')->first();
        $this->assertNotNull($user);
        $this->assertEquals('raw@example.com', $user->email);
    }

    public function testQueryBuilderInTransaction()
    {
        $connection = $this->connection;
        
        $connection->beginTransaction();
        
        $connection->table('TransactionUser')->insert([
            'name' => 'Builder User',
            'email' => 'builder@example.com'
        ]);
        
        $connection->commit();
        
        $user = TransactionUser::where('name', 'Builder User')->first();
        $this->assertNotNull($user);
        $this->assertEquals('builder@example.com', $user->email);
    }

    public function testQueryBuilderUpdateInTransaction()
    {
        $user = TransactionUser::create(['name' => 'Update Test', 'email' => 'update@example.com']);
        
        $connection = $this->connection;
        $connection->beginTransaction();
        
        $connection->table('TransactionUser')
            ->where('id', $user->id)
            ->update(['name' => 'Updated via Builder']);
        
        $connection->commit();
        
        $found = TransactionUser::find($user->id);
        $this->assertEquals('Updated via Builder', $found->name);
    }

    // ==========================================
    // 6. Transaction Helper Method
    // ==========================================

    public function testTransactionClosureExecution()
    {
        $connection = $this->connection;
        
        $result = $connection->transaction(function ($conn) {
            $user = TransactionUser::create(['name' => 'Closure User', 'email' => 'closure@example.com']);
            return $user->id;
        });
        
        $this->assertNotNull($result);
        
        $user = TransactionUser::find($result);
        $this->assertNotNull($user);
        $this->assertEquals('Closure User', $user->name);
    }

    public function testTransactionAutoCommitOnSuccess()
    {
        $connection = $this->connection;
        
        $userId = null;
        
        $connection->transaction(function () use (&$userId) {
            $user = TransactionUser::create(['name' => 'Auto Commit', 'email' => 'autocommit@example.com']);
            $userId = $user->id;
        });
        
        // Verify data was committed
        $user = TransactionUser::find($userId);
        $this->assertNotNull($user);
        $this->assertEquals('Auto Commit', $user->name);
    }

    public function testTransactionAutoRollbackOnException()
    {
        $connection = $this->connection;
        
        $userId = null;
        
        try {
            $connection->transaction(function () use (&$userId) {
                $user = TransactionUser::create(['name' => 'Will Rollback', 'email' => 'rollback@example.com']);
                $userId = $user->id;
                
                throw new Exception('Intentional error');
            });
        } catch (Exception $e) {
            // Expected exception
        }
        
        // Verify data was rolled back
        $user = TransactionUser::find($userId);
        $this->assertNull($user);
        $this->assertEquals(0, TransactionUser::count());
    }

    public function testTransactionReturnValue()
    {
        $connection = $this->connection;
        
        $result = $connection->transaction(function () {
            TransactionUser::create(['name' => 'Return Test', 'email' => 'return@example.com']);
            return 'success';
        });
        
        $this->assertEquals('success', $result);
    }

    public function testTransactionRetryLogic()
    {
        $connection = $this->connection;
        
        $attempts = 0;
        
        try {
            $connection->transaction(function () use (&$attempts) {
                $attempts++;
                
                if ($attempts < 3) {
                    throw new Exception('Retry needed');
                }
                
                return TransactionUser::create(['name' => 'Retry Success', 'email' => 'retry@example.com']);
            }, 3);
        } catch (Exception $e) {
            // May throw if all attempts fail
        }
        
        $this->assertEquals(3, $attempts);
        
        // Check if user was created on final attempt
        $user = TransactionUser::where('name', 'Retry Success')->first();
        $this->assertNotNull($user);
    }

    // ==========================================
    // 7. Error Handling
    // ==========================================

    public function testExceptionDuringTransaction()
    {
        $connection = $this->connection;
        
        $connection->beginTransaction();
        
        TransactionUser::create(['name' => 'Exception Test', 'email' => 'exception@example.com']);
        
        try {
            throw new Exception('Test exception');
        } catch (Exception $e) {
            $connection->rollBack();
        }
        
        // Verify rollback occurred
        $this->assertEquals(0, TransactionUser::count());
    }

    public function testNestedTransactionPrevention()
    {
        $connection = $this->connection;
        
        $connection->beginTransaction();
        
        // Get the transaction object
        $reflection = new \ReflectionClass($connection);
        $property = $reflection->getProperty('transaction');
        $property->setAccessible(true);
        $transaction = $property->getValue($connection);
        
        if ($transaction !== null) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Cannot begin a transaction within an existing transaction');
            
            $transaction->beginTransaction();
        }
        
        $connection->rollBack();
    }

    public function testCommitOnFinishedTransactionHandledGracefully()
    {
        $connection = $this->connection;
        
        $connection->beginTransaction();
        $user = TransactionUser::create(['name' => 'Commit Test', 'email' => 'commit@example.com']);
        $connection->commit();
        
        // Try to commit again - should not throw error
        $connection->commit();
        
        // Verify original commit worked
        $found = TransactionUser::find($user->id);
        $this->assertNotNull($found);
    }

    public function testRollbackOnFinishedTransactionHandledGracefully()
    {
        $connection = $this->connection;
        
        $connection->beginTransaction();
        TransactionUser::create(['name' => 'Rollback Test', 'email' => 'rollback@example.com']);
        $connection->rollBack();
        
        // Try to rollback again - should not throw error
        $connection->rollBack();
        
        // Verify original rollback worked
        $this->assertEquals(0, TransactionUser::count());
    }

    // ==========================================
    // 8. Edge Cases
    // ==========================================

    public function testEmptyTransaction()
    {
        $connection = $this->connection;
        
        $connection->beginTransaction();
        // Do nothing
        $connection->commit();
        
        // Should complete without error
        $this->assertEquals(0, $connection->transactionLevel());
    }

    public function testMultipleCommitCalls()
    {
        $connection = $this->connection;
        
        $connection->beginTransaction();
        $user = TransactionUser::create(['name' => 'Multi Commit', 'email' => 'multi@example.com']);
        $userId = $user->id;
        
        $connection->commit();
        $connection->commit(); // Second commit should be safe
        $connection->commit(); // Third commit should be safe
        
        // Verify data persisted from first commit
        $found = TransactionUser::find($userId);
        $this->assertNotNull($found);
    }

    public function testMultipleRollbackCalls()
    {
        $connection = $this->connection;
        
        $connection->beginTransaction();
        TransactionUser::create(['name' => 'Multi Rollback', 'email' => 'multirollback@example.com']);
        
        $connection->rollBack();
        $connection->rollBack(); // Second rollback should be safe
        $connection->rollBack(); // Third rollback should be safe
        
        // Verify data was rolled back
        $this->assertEquals(0, TransactionUser::count());
    }

    public function testTransactionCounterDoesNotGoNegative()
    {
        $connection = $this->connection;
        
        $this->assertEquals(0, $connection->transactionLevel());
        
        $connection->rollBack(); // Rollback without begin
        $this->assertEquals(0, $connection->transactionLevel());
        
        $connection->commit(); // Commit without begin
        $this->assertEquals(0, $connection->transactionLevel());
    }

    // ==========================================
    // 9. Integration with Existing Features
    // ==========================================

    public function testSoftDeletesInTransaction()
    {
        $connection = $this->connection;
        
        $connection->beginTransaction();
        
        $user = TransactionSoftDeleteUser::create(['name' => 'Soft Delete', 'email' => 'soft@example.com']);
        $userId = $user->id;
        
        $connection->commit();
        
        // Now soft delete in a transaction
        $connection->beginTransaction();
        
        $user->delete();
        $this->assertTrue($user->exists);
        $this->assertNotNull($user->deleted_at);
        
        $connection->commit();
        
        // Verify soft delete persisted
        $found = TransactionSoftDeleteUser::find($userId);
        $this->assertNull($found); // Should not find because it's soft deleted
        
        $foundWithTrashed = TransactionSoftDeleteUser::withTrashed()->find($userId);
        $this->assertNotNull($foundWithTrashed);
        $this->assertNotNull($foundWithTrashed->deleted_at);
    }

    public function testSoftDeleteRollback()
    {
        $user = TransactionSoftDeleteUser::create(['name' => 'Soft Delete Rollback', 'email' => 'softrollback@example.com']);
        $userId = $user->id;
        
        $connection = $this->connection;
        $connection->beginTransaction();
        
        $user->delete();
        
        $connection->rollBack();
        
        // Verify soft delete did not persist
        $found = TransactionSoftDeleteUser::find($userId);
        $this->assertNotNull($found);
        $this->assertNull($found->deleted_at);
    }

    public function testTimestampsInTransaction()
    {
        $connection = $this->connection;
        
        $connection->beginTransaction();
        
        $user = TransactionUser::create(['name' => 'Timestamp Test', 'email' => 'timestamp@example.com']);
        
        $this->assertNotNull($user->created_at);
        $this->assertNotNull($user->updated_at);
        
        $connection->commit();
        
        $found = TransactionUser::find($user->id);
        $this->assertNotNull($found->created_at);
        $this->assertNotNull($found->updated_at);
    }

    public function testModelEventsInTransaction()
    {
        $connection = $this->connection;
        
        $eventFired = false;
        
        TransactionUser::creating(function ($user) use (&$eventFired) {
            $eventFired = true;
        });
        
        $connection->beginTransaction();
        
        TransactionUser::create(['name' => 'Event Test', 'email' => 'event@example.com']);
        
        $this->assertTrue($eventFired, 'Model creating event should fire in transaction');
        
        $connection->commit();
    }

    public function testModelEventsInRollback()
    {
        $connection = $this->connection;
        
        $eventFired = false;
        
        TransactionUser::creating(function ($user) use (&$eventFired) {
            $eventFired = true;
        });
        
        $connection->beginTransaction();
        
        TransactionUser::create(['name' => 'Event Rollback Test', 'email' => 'eventrollback@example.com']);
        
        $this->assertTrue($eventFired, 'Model creating event should fire even if rolled back');
        
        $connection->rollBack();
        
        // Verify data was rolled back even though event fired
        $this->assertEquals(0, TransactionUser::count());
    }
}

