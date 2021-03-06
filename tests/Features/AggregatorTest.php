<?php

declare(strict_types=1);

namespace Tests\Features;

use App\Post;
use App\User;
use App\Wall;
use App\Thread;
use Tests\TestCase;
use Algolia\ScoutExtended\Searchable\AggregatorCollection;

final class AggregatorTest extends TestCase
{
    public function testWhenAggregagorIsNotBooted(): void
    {
        $usersIndexMock = $this->mockIndex('users');

        $usersIndexMock->shouldReceive('saveObjects')->once()->with(\Mockery::on(function ($argument) {
            return count($argument) === 1 && array_key_exists('email', $argument[0]) &&
                $argument[0]['objectID'] === 'App\User::1';
        }));

        $user = factory(User::class)->create();

        $usersIndexMock->shouldReceive('deleteBy')->once()->with([
            'tagFilters' => [
                'App\User::1',
            ],
        ]);

        $user->delete();
    }

    public function testAggregatorWithSearchableModel(): void
    {
        Wall::bootSearchable();

        $usersIndexMock = $this->mockIndex('users');
        $wallIndexMock = $this->mockIndex('wall');

        $usersIndexMock->shouldReceive('saveObjects')->once()->with(\Mockery::on(function ($argument) {
            return count($argument) === 1 && array_key_exists('email', $argument[0]) &&
                $argument[0]['objectID'] === 'App\User::1';
        }));
        $wallIndexMock->shouldReceive('saveObjects')->once()->with(\Mockery::on(function ($argument) {
            return count($argument) === 1 && array_key_exists('email', $argument[0]) &&
                $argument[0]['objectID'] === 'App\User::1';
        }));
        $user = factory(User::class)->create();

        $usersIndexMock->shouldReceive('deleteBy')->once()->with([
            'tagFilters' => [
                'App\User::1',
            ],
        ]);

        $wallIndexMock->shouldReceive('deleteBy')->once()->with([
            'tagFilters' => [
                'App\User::1',
            ],
        ]);

        $user->delete();
    }

    public function testAggregatorWithNonSearchableModel(): void
    {
        Wall::bootSearchable();

        $threadIndexMock = $this->mockIndex(Thread::class);
        $wallIndexMock = $this->mockIndex('wall');

        $threadIndexMock->shouldReceive('saveObjects')->once();
        $wallIndexMock->shouldReceive('saveObjects')->once()->with(\Mockery::on(function ($argument) {
            return count($argument) === 1 && array_key_exists('body', $argument[0]) &&
                $argument[0]['objectID'] === 'App\Thread::1';
        }));
        $thread = factory(Thread::class)->create();

        $threadIndexMock->shouldReceive('deleteBy')->once()->with([
            'tagFilters' => [
                'App\Thread::1',
            ],
        ]);

        $wallIndexMock->shouldReceive('deleteBy')->once()->with([
            'tagFilters' => [
                'App\Thread::1',
            ],
        ]);
        $thread->delete();
    }

    public function testAggregatorSoftDeleteModelWithoutSoftDeletesOnIndex(): void
    {
        Wall::bootSearchable();

        $wallIndexMock = $this->mockIndex('wall');

        // Laravel Scout restore calls twice the save objects.
        $wallIndexMock->shouldReceive('saveObjects')->times(3)->with(\Mockery::on(function ($argument) {
            return count($argument) === 1 && array_key_exists('subject', $argument[0]) &&
                $argument[0]['objectID'] === 'App\Post::1';
        }));
        $wallIndexMock->shouldReceive('deleteBy')->times(3)->with([
            'tagFilters' => [
                'App\Post::1',
            ],
        ]);
        $post = factory(Post::class)->create();
        $post->delete();
        $post->restore();
        $post->forceDelete();
    }

    public function testAggregatorSoftDeleteModelWithSoftDeletesOnIndex(): void
    {
        Wall::bootSearchable();

        $this->app['config']->set('scout.soft_delete', true);

        $wallIndexMock = $this->mockIndex('wall');

        // Laravel Scout force Delete calls once the save() method.
        $wallIndexMock->shouldReceive('saveObjects')->times(3)->with(\Mockery::on(function ($argument) {
            return count($argument) === 1 && array_key_exists('subject', $argument[0]) &&
                $argument[0]['objectID'] === 'App\Post::1';
        }));
        $post = factory(Post::class)->create();
        $post->delete();

        $wallIndexMock->shouldReceive('deleteBy')->once()->with([
            'tagFilters' => [
                'App\Post::1',
            ],
        ]);
        $post->forceDelete();
    }

    public function testAggregatorSearch(): void
    {
        Wall::bootSearchable();

        $threadIndexMock = $this->mockIndex(Thread::class);
        $wallIndexMock = $this->mockIndex('wall');

        $threadIndexMock->shouldReceive('saveObjects')->times(2);
        $wallIndexMock->shouldReceive('saveObjects')->times(4);
        $wallIndexMock->shouldReceive('search')->once()->andReturn([
            'hits' => [
                ['objectID' => 'App\Post::1'],
                ['objectID' => 'App\Thread::1'],
                ['objectID' => 'App\Thread::2'],
                ['objectID' => 'App\Post::2'],
            ],
        ]);

        $post = factory(Post::class, 2)->create();
        $thread = factory(Thread::class, 2)->create();

        $models = Wall::search('input')->get();
        /* @var $models \Illuminate\Database\Eloquent\Collection */
        $this->assertCount(4, $models);

        $this->assertInstanceOf(Post::class, $models->get(0));
        $this->assertEquals($post[0]->subject, $models->get(0)->subject);
        $this->assertEquals($post[0]->id, $models->get(0)->id);

        $this->assertInstanceOf(Thread::class, $models->get(1));
        $this->assertEquals($thread[0]->body, $models->get(1)->body);
        $this->assertEquals($thread[0]->id, $models->get(1)->id);

        $this->assertInstanceOf(Thread::class, $models->get(2));
        $this->assertEquals($thread[1]->body, $models->get(2)->body);
        $this->assertEquals($thread[1]->id, $models->get(2)->id);

        $this->assertInstanceOf(Post::class, $models->get(3));
        $this->assertEquals($post[1]->subject, $models->get(3)->subject);
        $this->assertEquals($post[1]->id, $models->get(3)->id);
    }

    public function testSerializationOfCollection(): void
    {
        $aggregators = factory(Post::class, 100)->create()->map(function ($model) {
            return Wall::create(Post::find($model->id));
        })->toArray();

        $collection = AggregatorCollection::make($aggregators);

        $collectionQueued = unserialize(serialize(clone $collection));

        $this->assertEquals(Wall::class, $collectionQueued->aggregator);
        $this->assertEquals($collection->toArray(), $collectionQueued->toArray());
    }
}
