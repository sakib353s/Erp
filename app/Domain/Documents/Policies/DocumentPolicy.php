<?php

namespace App\Domain\Documents\Policies;

use App\Domain\Documents\Document;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;

class DocumentPolicy
{
    public function __construct(protected PermissionCatalog $catalog) {}

    public function viewAny(User $user): bool
    {
        return $this->catalog->allows($user, 'documents.view');
    }

    public function view(User $user, Document $document): bool
    {
        return $this->catalog->allows($user, 'documents.view');
    }

    public function create(User $user): bool
    {
        return $this->catalog->allows($user, 'documents.upload');
    }

    public function download(User $user, Document $document): bool
    {
        return $this->catalog->allows($user, 'documents.download');
    }

    public function update(User $user, Document $document): bool
    {
        return $this->catalog->allows($user, 'documents.manage');
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->catalog->allows($user, 'documents.manage');
    }
}
