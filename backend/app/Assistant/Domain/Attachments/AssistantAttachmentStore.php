<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Attachments;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Holds an image the organizer dropped into the chat (a flyer, typically) long
 * enough for the model to read it and for a tool to attach it to the event the
 * conversation ends up creating.
 *
 * Files live on the private disk under a per-account folder and the id is a
 * UUID, so an id from another account resolves to nothing: the folder is part
 * of the path, not the request. They are pruned after an hour on the next
 * upload from the same account, which is the only moment anyone is looking.
 */
class AssistantAttachmentStore
{
    private const ROOT = 'assistant-attachments';
    private const TTL_SECONDS = 3600;

    public function __construct(
        private readonly Filesystem $disk,
    )
    {
    }

    public function store(UploadedFile $file, int $accountId): AssistantAttachment
    {
        $this->prune($accountId);

        $id = (string)Str::uuid();
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'jpg');
        $path = $this->folder($accountId) . '/' . $id . '.' . $extension;

        $this->disk->put($path, (string)file_get_contents($file->getRealPath()));

        return new AssistantAttachment(
            id: $id,
            path: $path,
            mimeType: (string)$file->getMimeType(),
            originalName: Str::limit($file->getClientOriginalName(), 120, ''),
            sizeBytes: (int)$file->getSize(),
        );
    }

    public function find(string $id, int $accountId): ?AssistantAttachment
    {
        if (!Str::isUuid($id)) {
            return null;
        }

        foreach ($this->disk->files($this->folder($accountId)) as $path) {
            if (pathinfo($path, PATHINFO_FILENAME) !== $id) {
                continue;
            }

            return new AssistantAttachment(
                id: $id,
                path: $path,
                mimeType: (string)$this->disk->mimeType($path),
                originalName: basename($path),
                sizeBytes: (int)$this->disk->size($path),
            );
        }

        return null;
    }

    public function contents(AssistantAttachment $attachment): string
    {
        return (string)$this->disk->get($attachment->path);
    }

    /**
     * A temporary file on local disk, shaped as an UploadedFile so the existing
     * image pipeline (validation, storage, variants) treats it like any upload.
     */
    public function asUploadedFile(AssistantAttachment $attachment): UploadedFile
    {
        $temp = tempnam(sys_get_temp_dir(), 'assistant-flyer-');
        file_put_contents($temp, $this->contents($attachment));

        return new UploadedFile(
            path: $temp,
            originalName: $attachment->originalName,
            mimeType: $attachment->mimeType,
            error: null,
            test: true,
        );
    }

    public function delete(AssistantAttachment $attachment): void
    {
        $this->disk->delete($attachment->path);
    }

    private function prune(int $accountId): void
    {
        $cutoff = time() - self::TTL_SECONDS;

        foreach ($this->disk->files($this->folder($accountId)) as $path) {
            if ($this->disk->lastModified($path) < $cutoff) {
                $this->disk->delete($path);
            }
        }
    }

    private function folder(int $accountId): string
    {
        return self::ROOT . '/' . $accountId;
    }
}
