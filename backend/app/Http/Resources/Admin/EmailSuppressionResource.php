<?php

declare(strict_types=1);

namespace HiEvents\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailSuppressionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->field('id', 'getId'),
            'email' => $this->field('email', 'getEmail'),
            'reason' => $this->field('reason', 'getReason'),
            'bounce_type' => $this->field('bounce_type', 'getBounceType'),
            'bounce_sub_type' => $this->field('bounce_sub_type', 'getBounceSubType'),
            'complaint_type' => $this->field('complaint_type', 'getComplaintType'),
            'source' => $this->field('source', 'getSource'),
            'account_id' => $this->field('account_id', 'getAccountId'),
            'account_name' => data_get($this->resource, 'account_name'),
            'created_at' => $this->field('created_at', 'getCreatedAt'),
        ];
    }

    /**
     * Read a field from either an Eloquent model (the list path, snake_case
     * attributes) or a domain object (the create path, protected props with
     * getters). Domain objects don't expose properties directly, so prefer the
     * getter when present.
     */
    private function field(string $attribute, string $getter): mixed
    {
        $resource = $this->resource;

        if (is_object($resource) && method_exists($resource, $getter)) {
            return $resource->{$getter}();
        }

        return data_get($resource, $attribute);
    }
}
