<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dataset;
use App\Services\SpreadsheetImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

class DatasetController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Dataset::latest()->get()->map(fn (Dataset $d) => $this->meta($d)),
        ]);
    }

    public function store(Request $request, SpreadsheetImporter $importer): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'extensions:xlsx,xls,csv'],
            'name' => ['nullable', 'string', 'max:150'],
        ]);

        $file = $request->file('file');
        $name = $request->input('name') ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        try {
            $dataset = $importer->import(
                $file->getRealPath(),
                strtolower($file->getClientOriginalExtension()),
                $name,
                $file->getClientOriginalName(),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Could not read the file. Is it a valid Excel or CSV file?'], 422);
        }

        return response()->json(['data' => $this->meta($dataset)], 201);
    }

    public function show(Dataset $dataset): JsonResponse
    {
        return response()->json(['data' => $this->meta($dataset)]);
    }

    public function destroy(Dataset $dataset): JsonResponse
    {
        $dataset->delete();

        return response()->json(null, 204);
    }

    private function meta(Dataset $d): array
    {
        return [
            'name' => $d->name,
            'slug' => $d->slug,
            'original_filename' => $d->original_filename,
            'row_count' => $d->row_count,
            'columns' => array_map(fn ($c) => ['name' => $c['label'], 'type' => $c['type']], $d->columns),
            'created_at' => $d->created_at?->toIso8601String(),
        ];
    }
}
