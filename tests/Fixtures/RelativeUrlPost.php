<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Siberfx\XAutopost\Contracts\ShareableOnX;
use Siberfx\XAutopost\Posts\XPost;

/** A live model whose toXPost() throws: it builds a relative URL. */
final class RelativeUrlPost extends Model implements ShareableOnX
{
    protected $table = 'posts';

    protected $guarded = [];

    public function isLiveForX(): bool
    {
        return true;
    }

    public function toXPost(): XPost
    {
        return XPost::make('/posts/'.$this->getKey(), (string) $this->title);
    }
}
