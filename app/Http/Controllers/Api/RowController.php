<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dataset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RowController extends Controller
{
    /**
     * GET /api/datasets/{slug}/rows
     *   ?filter[Customer]=Ahmed       exact match on a column (use the original header)
     *   ?search=text                  text contained in any text column
     *   ?sort=Amount or ?sort=-Amount sort by a column (prefix - for descending)
     *   ?per_page=50&page=2
     */
    public function index(Request $request, Dataset $dataset): JsonResponse
    {
        $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'filter' => ['nullable', 'array'],
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'string', 'max:150'],
        ]);

        $query = $dataset->rows();

        foreach ((array) $request->input('filter', []) as $label => $value) {
            $column = $dataset->columnByLabel((string) $label);
            if (! $column || ! is_scalar($value)) {
                return response()->json(['message' => "Unknown column [{$label}]."], 422);
            }
            $query->where('data->' . $column['key'], $column['type'] === 'number' && is_numeric($value) ? $value + 0 : $value);
        }

        if ($term = $request->input('search')) {
            $like = '%' . $term . '%';
            $query->where(function ($q) use ($dataset, $like) {
                foreach ($dataset->columns as $column) {
                    if ($column['type'] === 'text') {
                        $q->orWhere('data->' . $column['key'], 'like', $like);
                    }
                }
            });
        }

        if ($sort = $request->input('sort')) {
            $desc = str_starts_with($sort, '-');
            $column = $dataset->columnByLabel(ltrim($sort, '-'));
            if (! $column) {
                return response()->json(['message' => 'Unknown sort column.'], 422);
            }
            $query->orderBy('data->' . $column['key'], $desc ? 'desc' : 'asc');
        }
        $query->orderBy('id');

        $page = $query->paginate($request->integer('per_page', 50))->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn ($row) => $dataset->present($row))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** GET /api/datasets/{slug}/summary?column=Amount : count, sum, avg, min, max of a numeric column. */
    public function summary(Request $request, Dataset $dataset): JsonResponse
    {
        $label = (string) $request->query('column', '');
        $column = $dataset->columnByLabel($label);

        if (! $column) {
            return response()->json(['message' => 'Unknown column.'], 422);
        }
        if ($column['type'] !== 'number') {
            return response()->json(['message' => 'Column is not numeric.'], 422);
        }

        $values = [];
        $dataset->rows()->select(['id', 'data'])->chunkById(1000, function ($rows) use (&$values, $column) {
            foreach ($rows as $row) {
                $v = $row->data[$column['key']] ?? null;
                if ($v !== null && is_numeric($v)) {
                    $values[] = $v + 0;
                }
            }
        });

        $count = count($values);

        return response()->json(['data' => [
            'column' => $label,
            'count' => $count,
            'sum' => $count ? round(array_sum($values), 2) : 0,
            'avg' => $count ? round(array_sum($values) / $count, 2) : null,
            'min' => $count ? min($values) : null,
            'max' => $count ? max($values) : null,
        ]]);
    }
}
