<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Dataset extends Model
{
    protected $fillable = ['name', 'slug', 'original_filename', 'columns', 'row_count'];

    protected function casts(): array
    {
        return ['columns' => 'array'];
    }

    public function rows()
    {
        return $this->hasMany(DatasetRow::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** Find a column definition by its original header text. */
    public function columnByLabel(string $label): ?array
    {
        foreach ($this->columns as $column) {
            if ($column['label'] === $label) {
                return $column;
            }
        }

        return null;
    }

    /** Turn a stored row into {label: value}. */
    public function present(DatasetRow $row): array
    {
        $out = ['id' => $row->id];
        foreach ($this->columns as $column) {
            $out[$column['label']] = $row->data[$column['key']] ?? null;
        }

        return $out;
    }
}
