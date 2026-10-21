<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CellBlock;
use App\Models\Pdl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Record Officer's list of cell blocks / dorms and their capacities. This is
 * what the Cell/Block dropdown on the PDL forms is built from.
 */
class CellBlockController extends Controller
{
    private const MESSAGES = [
        'name.regex' => 'Cell block name may only contain letters, numbers, spaces and ( ) # . / -',
        'name.unique' => 'A cell block with this name already exists.',
    ];

    public function index(Request $request)
    {
        $cellBlocks = CellBlock::withOccupancy()->with('visitationRules')->orderBy('name')->get();

        $stats = [
            'blocks' => $cellBlocks->where('is_active', true)->count(),
            'capacity' => $cellBlocks->where('is_active', true)->sum('capacity'),
            'occupied' => $cellBlocks->sum('occupied_count'),
            // Over the recommended capacity (still allowed; shown in yellow).
            'over' => $cellBlocks->where('is_active', true)->filter->isOver()->count(),
        ];

        // From the "Over Capacity" card: list only the overcrowded dorms.
        $overOnly = $request->boolean('over');
        if ($overOnly) {
            $cellBlocks = $cellBlocks->filter(fn ($b) => $b->is_active && $b->isOver())->values();
        }

        return view('cell-blocks.index', compact('cellBlocks', 'stats', 'overOnly'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50', "regex:/^[\pL\pN][\pL\pN\s()#.\/\-]*$/u", Rule::unique('cell_blocks', 'name')],
            'capacity' => ['required', 'integer', 'min:1', 'max:1000'],
            'designation' => ['required', Rule::in(CellBlock::DESIGNATIONS)],
        ], self::MESSAGES);

        $block = CellBlock::create([...$validated, 'name' => trim($validated['name']), 'is_active' => true]);

        $this->logAudit('create', $block->cell_block_id, "Added cell block {$block->name} (capacity {$block->capacity}, {$block->designationLabel()})");

        return redirect()->route('cell-blocks.index')->with('success', "Cell block {$block->name} added.");
    }

    public function update(Request $request, CellBlock $cellBlock)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50', "regex:/^[\pL\pN][\pL\pN\s()#.\/\-]*$/u", Rule::unique('cell_blocks', 'name')->ignore($cellBlock->cell_block_id, 'cell_block_id')],
            'capacity' => ['required', 'integer', 'min:1', 'max:1000'],
            'designation' => ['required', Rule::in(CellBlock::DESIGNATIONS)],
            'is_active' => ['nullable', 'boolean'],
        ], self::MESSAGES);
        $validated['name'] = trim($validated['name']);
        $validated['is_active'] = $request->boolean('is_active');

        // Capacity is a recommendation, so it may be set below the current
        // headcount (the block then shows as over capacity).

        $oldName = $cellBlock->name;

        try {
            DB::transaction(function () use ($cellBlock, $validated, $oldName) {
                $cellBlock->update($validated);

                // PDLs reference the block by name, so a rename carries over to them.
                if ($oldName !== $validated['name']) {
                    Pdl::where('cell_block', $oldName)->update(['cell_block' => $validated['name']]);
                }
            });
        } catch (\Throwable $e) {
            Log::error('Cell block update failed: ' . $e->getMessage());
            return redirect()->back()->withInput()->with('error', 'Could not save this cell block. Please try again.');
        }

        $this->logAudit(
            'update',
            $cellBlock->cell_block_id,
            "Updated cell block {$oldName}" . ($oldName !== $cellBlock->name ? " → {$cellBlock->name}" : '')
                . " (capacity {$cellBlock->capacity}, {$cellBlock->designationLabel()}, " . ($cellBlock->is_active ? 'active' : 'inactive') . ')'
        );

        return redirect()->route('cell-blocks.index')->with('success', "Cell block {$cellBlock->name} updated.");
    }

    private function logAudit(string $actionType, int $recordId, string $description): void
    {
        try {
            AuditLog::record($actionType, 'cell_blocks', $recordId, $description, \App\Models\Module::CODE_PDL_MANAGEMENT);
        } catch (\Throwable $e) {
            Log::warning('Audit log write failed (main action still succeeded): ' . $e->getMessage());
        }
    }
}
