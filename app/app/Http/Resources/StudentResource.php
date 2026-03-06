<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'admission_number' => $this->admission_number,
            'admission_date' => $this->admission_date?->format('Y-m-d'),
            
            // User information
            'first_name' => $this->user->first_name,
            'last_name' => $this->user->last_name,
            'middle_name' => $this->user->middle_name,
            'full_name' => $this->user->first_name . ' ' . $this->user->last_name,
            'email' => $this->user->email,
            'phone' => $this->user->phone,
            'gender' => $this->user->gender,
            'date_of_birth' => $this->user->date_of_birth?->format('Y-m-d'),
            'address' => $this->user->address,
            'avatar' => $this->user->avatar,
            
            // Student specific
            'class' => $this->whenLoaded('class', function() {
                return [
                    'id' => $this->class->id,
                    'name' => $this->class->name,
                ];
            }),
            'blood_group' => $this->blood_group,
            'genotype' => $this->genotype,
            'nationality' => $this->nationality,
            'state_of_origin' => $this->state_of_origin,
            'religion' => $this->religion,
            'status' => $this->status,
            
            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}