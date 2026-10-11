<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Siberfx\XAutopost\Concerns\PostsToX;
use Siberfx\XAutopost\Contracts\ShareableOnX;
use Siberfx\XAutopost\Posts\XPost;

final class Post extends Model implements ShareableOnX
{
    use PostsToX;

    protected $guarded = [];

    protected $casts = ['status' => PostStatus::class];

    public function isLiveForX(): bool
    {
        return $this->status === PostStatus::Published;
    }

    public function toXPost(): XPost
    {
        return XPost::make('https://example.com/posts/'.$this->getKey(), (string) $this->title)
            ->description('About '.$this->title);
    }
}
