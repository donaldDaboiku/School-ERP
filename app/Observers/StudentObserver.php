<?php

namespace App\Observers;

use App\Events\StudentCreated;
use App\Models\Classes;
use App\Models\Student;
use Illuminate\Support\Facades\Log;

class StudentObserver
{
    /**
     * Handle events after all transactions are committed.
     *
     * @var bool
     */
    public $afterCommit = true;

    public function creating(Student $student): void
    {
        try {
            if (empty($student->admission_date)) {
                $student->admission_date = now();
            }
        } catch (\Exception $e) {
            Log::error('Student creating failed', [
                'error' => $e->getMessage(),
                'student' => $student->toArray(),
            ]);
            throw $e;
        }
    }

    public function created(Student $student): void
    {
        try {
            event(new StudentCreated($student));

            $this->updateClassCounts(null, $student->class_id);

            Log::info('Student created', [
                'student_id' => $student->id,
                'class_id' => $student->class_id,
            ]);
        } catch (\Exception $e) {
            Log::error('Student created handler failed', [
                'student_id' => $student->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function updated(Student $student): void
    {
        try {
            if ($student->wasChanged('class_id')) {
                $originalClassId = $student->getOriginal('class_id');
                $this->updateClassCounts($originalClassId, $student->class_id);
            }
        } catch (\Exception $e) {
            Log::error('Student updated handler failed', [
                'student_id' => $student->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function deleted(Student $student): void
    {
        $this->updateClassCounts($student->class_id, null);
    }

    public function restored(Student $student): void
    {
        $this->updateClassCounts(null, $student->class_id);
    }

    private function updateClassCounts(?int $fromClassId, ?int $toClassId): void
    {
        if ($fromClassId) {
            $fromClass = Classes::find($fromClassId);
            if ($fromClass) {
                $fromClass->updateStudentCount();
            }
        }

        if ($toClassId && $toClassId !== $fromClassId) {
            $toClass = Classes::find($toClassId);
            if ($toClass) {
                $toClass->updateStudentCount();
            }
        }
    }
}
