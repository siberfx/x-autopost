<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Siberfx\XAutopost\Contracts\ShareableOnX;
use Siberfx\XAutopost\Models\XSharedPost;
use Siberfx\XAutopost\XManager;
use Throwable;

final class ShareCommand extends Command
{
    protected $signature = 'x:share
        {model : Morph alias or model class}
        {id : Primary key}
        {--force : Share again even if it was already posted}';

    protected $description = 'Share a model on X now';

    public function handle(XManager $x): int
    {
        $type = $this->stringArgument('model');
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class) || ! is_subclass_of($class, ShareableOnX::class)) {
            $this->components->error("[{$type}] is not a shareable model.");

            return self::FAILURE;
        }

        $id = $this->stringArgument('id');

        /** @var (Model&ShareableOnX)|null $model */
        $model = $class::query()->find($id);

        if ($model === null) {
            $this->components->error("Record [{$id}] not found.");

            return self::FAILURE;
        }

        if (! $this->option('force') && $x->wasPosted($model)) {
            $this->components->warn('This was already posted to X. Use --force to share it again.');

            return self::FAILURE;
        }

        try {
            $post = $x->share($model, XSharedPost::TRIGGER_CONSOLE);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info($post->post_id !== null
            ? "Shared on X: {$post->post_id}"
            : 'Shared on X (X returned no post id).');

        return self::SUCCESS;
    }

    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_scalar($value) ? (string) $value : '';
    }
}
