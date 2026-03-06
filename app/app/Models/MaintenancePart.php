<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;    
use Illuminate\Support\Facades\DB;      

class MaintenancePart extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'maintenance_parts';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'part_number',
        'part_name',
        'description',
        'category',
        'manufacturer',
        'supplier',
        'unit_price',
        'quantity',
        'minimum_stock',
        'reorder_level',
        'location',
        'bin_number',
        'serial_number',
        'batch_number',
        'purchase_date',
        'warranty_expiry',
        'last_used',
        'usage_count',
        'condition',
        'is_consumable',
        'is_active',
        'notes',
        'created_by',
        'updated_by'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'unit_price' => 'decimal:2',
        'quantity' => 'integer',
        'minimum_stock' => 'integer',
        'reorder_level' => 'integer',
        'purchase_date' => 'date',
        'warranty_expiry' => 'date',
        'last_used' => 'datetime',
        'usage_count' => 'integer',
        'is_consumable' => 'boolean',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    /**
     * The attributes that should be appended.
     *
     * @var array<string>
     */
    protected $appends = [
        'total_value',
        'stock_status',
        'needs_reorder',
        'category_display',
        'condition_display',
        'is_warranty_valid',
        'days_until_warranty_expiry',
        'usage_rate',
        'supplier_info'
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $with = ['creator', 'updater'];

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($part) {
            // Generate part number if not provided
            if (empty($part->part_number)) {
                $part->part_number = self::generatePartNumber($part);
            }
            
            // Set default quantity
            if (is_null($part->quantity)) {
                $part->quantity = 0;
            }
            
            // Set default minimum_stock
            if (is_null($part->minimum_stock)) {
                $part->minimum_stock = 5;
            }
            
            // Set default reorder_level
            if (is_null($part->reorder_level)) {
                $part->reorder_level = 10;
            }
            
            // Set default condition
            if (empty($part->condition)) {
                $part->condition = 'new';
            }
            
            // Set default is_active
            if (is_null($part->is_active)) {
                $part->is_active = true;
            }
            
            // Set default is_consumable
            if (is_null($part->is_consumable)) {
                $part->is_consumable = true;
            }
            
            // Set created_by if not set
            if (empty($part->created_by) && Auth::check()) {
                $part->created_by = Auth::id();
            }
            
            // Validate part
            self::validatePart($part);
            
            Log::info('Maintenance part creating', [
                'part_number' => $part->part_number,
                'part_name' => $part->part_name,
                'quantity' => $part->quantity,
                'created_by' => $part->created_by
            ]);
        });

        static::updating(function ($part) {
            // Update updated_by
            if (Auth::check()) {
                $part->updated_by = Auth::id();
            }
            
            // Validate part on update
            self::validatePart($part);
            
            // Prevent negative quantity
            if ($part->isDirty('quantity') && $part->quantity < 0) {
                throw new \Exception('Quantity cannot be negative');
            }
        });

        static::saved(function ($part) {
            // Clear relevant cache
            Cache::forget("maintenance_part_{$part->id}");
            Cache::forget("maintenance_part_number_{$part->part_number}");
            Cache::tags([
                "maintenance_parts_category_{$part->category}",
                "maintenance_parts_stock_status_{$part->stock_status}",
                "maintenance_parts_active"
            ])->flush();
        });

        static::deleted(function ($part) {
            // Clear cache
            Cache::forget("maintenance_part_{$part->id}");
            Cache::forget("maintenance_part_number_{$part->part_number}");
            Cache::tags([
                "maintenance_parts_category_{$part->category}",
                "maintenance_parts_stock_status_{$part->stock_status}",
                "maintenance_parts_active"
            ])->flush();
        });
    }

    /**
     * Get the user who created this part.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this part.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get the maintenance usages for this part.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function maintenanceUsages()
    {
        return $this->hasMany(MaintenancePartUsage::class, 'part_id');
    }

    /**
     * Get the purchase orders for this part.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrderItem::class, 'part_id');
    }

    /**
     * Get total value of stock.
     *
     * @return float
     */
    public function getTotalValueAttribute()
    {
        return $this->quantity * $this->unit_price;
    }

    /**
     * Get stock status.
     *
     * @return string
     */
    public function getStockStatusAttribute()
    {
        if ($this->quantity <= 0) {
            return 'out_of_stock';
        } elseif ($this->quantity <= $this->minimum_stock) {
            return 'low_stock';
        } elseif ($this->quantity <= $this->reorder_level) {
            return 'reorder';
        } else {
            return 'in_stock';
        }
    }

    /**
     * Check if part needs reorder.
     *
     * @return bool
     */
    public function getNeedsReorderAttribute()
    {
        return $this->quantity <= $this->reorder_level;
    }

    /**
     * Get category display name.
     *
     * @return string
     */
    public function getCategoryDisplayAttribute()
    {
        $categories = [
            'electrical' => 'Electrical',
            'mechanical' => 'Mechanical',
            'electronic' => 'Electronic',
            'computer' => 'Computer Parts',
            'furniture' => 'Furniture Parts',
            'plumbing' => 'Plumbing',
            'hardware' => 'Hardware',
            'consumable' => 'Consumable',
            'other' => 'Other'
        ];
        
        return $categories[$this->category] ?? ucfirst($this->category);
    }

    /**
     * Get condition display name.
     *
     * @return string
     */
    public function getConditionDisplayAttribute()
    {
        $conditions = [
            'new' => 'New',
            'refurbished' => 'Refurbished',
            'used_good' => 'Used - Good',
            'used_fair' => 'Used - Fair',
            'used_poor' => 'Used - Poor',
            'damaged' => 'Damaged'
        ];
        
        return $conditions[$this->condition] ?? ucfirst($this->condition);
    }

    /**
     * Check if warranty is valid.
     *
     * @return bool
     */
    public function getIsWarrantyValidAttribute()
    {
        if (!$this->warranty_expiry) {
            return false;
        }
        
        return now()->lt($this->warranty_expiry);
    }

    /**
     * Get days until warranty expiry.
     *
     * @return int|null
     */
    public function getDaysUntilWarrantyExpiryAttribute()
    {
        if (!$this->warranty_expiry) {
            return null;
        }
        
        return now()->diffInDays($this->warranty_expiry, false);
    }

    /**
     * Get usage rate (per month).
     *
     * @return float
     */
    public function getUsageRateAttribute()
    {
        if ($this->usage_count <= 0) {
            return 0;
        }
        
        $firstUsage = $this->maintenanceUsages()->orderBy('used_at')->first();
        if (!$firstUsage) {
            return 0;
        }
        
        $monthsUsed = now()->diffInMonths($firstUsage->used_at);
        if ($monthsUsed === 0) {
            $monthsUsed = 1;
        }
        
        return round($this->usage_count / $monthsUsed, 2);
    }

    /**
     * Get supplier information.
     *
     * @return array|null
     */
    public function getSupplierInfoAttribute()
    {
        if (!$this->supplier) {
            return null;
        }
        
        return [
            'name' => $this->supplier,
            'contact' => null, // Could be extended to include contact info
            'lead_time_days' => 7 // Default lead time
        ];
    }

    /**
     * Use part in maintenance.
     *
     * @param  int  $quantity
     * @param  int  $maintenanceId
     * @param  string  $maintenanceType
     * @param  string|null  $notes
     * @return MaintenancePartUsage
     */
    public function useInMaintenance($quantity, $maintenanceId, $maintenanceType, $notes = null)
    {
        if ($quantity > $this->quantity) {
            throw new \Exception("Insufficient stock. Available: {$this->quantity}, Requested: {$quantity}");
        }
        
        if ($quantity <= 0) {
            throw new \Exception('Quantity must be greater than 0');
        }
        
        // Reduce stock
        $this->quantity -= $quantity;
        $this->usage_count += $quantity;
        $this->last_used = now();
        $this->save();
        
        // Create usage record
        $usage = MaintenancePartUsage::create([
            'part_id' => $this->id,
            'maintenance_id' => $maintenanceId,
            'maintenance_type' => $maintenanceType,
            'quantity_used' => $quantity,
            'unit_price_at_time' => $this->unit_price,
            'total_cost' => $quantity * $this->unit_price,
            'used_at' => now(),
            'used_by' => Auth::id(),
            'notes' => $notes
        ]);
        
        Log::info('Maintenance part used', [
            'part_id' => $this->id,
            'part_name' => $this->part_name,
            'quantity_used' => $quantity,
            'maintenance_id' => $maintenanceId,
            'maintenance_type' => $maintenanceType,
            'used_by' => Auth::id()
        ]);
        
        return $usage;
    }

    /**
     * Add stock.
     *
     * @param  int  $quantity
     * @param  float|null  $unitPrice
     * @param  string|null  $batchNumber
     * @param  \DateTime|null  $purchaseDate
     * @param  \DateTime|null  $warrantyExpiry
     * @return bool
     */
    public function addStock($quantity, $unitPrice = null, $batchNumber = null, $purchaseDate = null, $warrantyExpiry = null)
    {
        if ($quantity <= 0) {
            throw new \Exception('Quantity must be greater than 0');
        }
        
        $this->quantity += $quantity;
        
        if ($unitPrice !== null) {
            // Update unit price (average if needed)
            if ($this->quantity > 0) {
                $totalValue = ($this->quantity - $quantity) * $this->unit_price + ($quantity * $unitPrice);
                $this->unit_price = round($totalValue / $this->quantity, 2);
            } else {
                $this->unit_price = $unitPrice;
            }
        }
        
        if ($batchNumber) {
            $this->batch_number = $batchNumber;
        }
        
        if ($purchaseDate) {
            $this->purchase_date = $purchaseDate;
        }
        
        if ($warrantyExpiry) {
            $this->warranty_expiry = $warrantyExpiry;
        }
        
        $this->save();
        
        Log::info('Maintenance part stock added', [
            'part_id' => $this->id,
            'part_name' => $this->part_name,
            'quantity_added' => $quantity,
            'new_quantity' => $this->quantity,
            'added_by' => Auth::id()
        ]);
        
        return true;
    }

    /**
     * Remove stock.
     *
     * @param  int  $quantity
     * @param  string  $reason
     * @return bool
     */
    public function removeStock($quantity, $reason)
    {
        if ($quantity > $this->quantity) {
            throw new \Exception("Insufficient stock. Available: {$this->quantity}, Requested: {$quantity}");
        }
        
        if ($quantity <= 0) {
            throw new \Exception('Quantity must be greater than 0');
        }
        
        $this->quantity -= $quantity;
        $this->save();
        
        Log::info('Maintenance part stock removed', [
            'part_id' => $this->id,
            'part_name' => $this->part_name,
            'quantity_removed' => $quantity,
            'reason' => $reason,
            'new_quantity' => $this->quantity,
            'removed_by' => Auth::id()
        ]);
        
        return true;
    }

    /**
     * Create purchase requisition.
     *
     * @param  int  $quantity
     * @param  User  $requester
     * @param  string  $urgency
     * @param  string|null  $notes
     * @return PurchaseRequisition
     */
    public function createPurchaseRequisition($quantity, $requester, $urgency = 'normal', $notes = null)
    {
        if ($quantity <= 0) {
            throw new \Exception('Quantity must be greater than 0');
        }
        
        $requisition = PurchaseRequisition::create([
            'part_id' => $this->id,
            'requester_id' => $requester->id,
            'quantity' => $quantity,
            'urgency' => $urgency,
            'status' => 'pending',
            'estimated_cost' => $quantity * $this->unit_price,
            'notes' => $notes ?? "Reorder for {$this->part_name} (Part#: {$this->part_number})",
            'created_by' => Auth::id()
        ]);
        
        Log::info('Purchase requisition created for part', [
            'part_id' => $this->id,
            'part_name' => $this->part_name,
            'quantity' => $quantity,
            'requisition_id' => $requisition->id,
            'requester_id' => $requester->id,
            'created_by' => Auth::id()
        ]);
        
        return $requisition;
    }

    /**
     * Get part details.
     *
     * @return array
     */
    public function getDetails()
    {
        return [
            'id' => $this->id,
            'part_number' => $this->part_number,
            'part_name' => $this->part_name,
            'description' => $this->description,
            'category' => $this->category,
            'category_display' => $this->category_display,
            'manufacturer' => $this->manufacturer,
            'supplier' => $this->supplier,
            'supplier_info' => $this->supplier_info,
            'unit_price' => $this->unit_price,
            'quantity' => $this->quantity,
            'minimum_stock' => $this->minimum_stock,
            'reorder_level' => $this->reorder_level,
            'total_value' => $this->total_value,
            'location' => $this->location,
            'bin_number' => $this->bin_number,
            'serial_number' => $this->serial_number,
            'batch_number' => $this->batch_number,
            'purchase_date' => $this->purchase_date,
            'warranty_expiry' => $this->warranty_expiry,
            'last_used' => $this->last_used,
            'usage_count' => $this->usage_count,
            'usage_rate' => $this->usage_rate,
            'condition' => $this->condition,
            'condition_display' => $this->condition_display,
            'is_consumable' => $this->is_consumable,
            'is_active' => $this->is_active,
            'stock_status' => $this->stock_status,
            'needs_reorder' => $this->needs_reorder,
            'is_warranty_valid' => $this->is_warranty_valid,
            'days_until_warranty_expiry' => $this->days_until_warranty_expiry,
            'notes' => $this->notes,
            'maintenance_usages' => $this->maintenanceUsages()->count(),
            'total_usage_cost' => $this->maintenanceUsages()->sum('total_cost')
        ];
    }

    /**
     * Generate part number.
     *
     * @param  MaintenancePart  $part
     * @return string
     */
    private static function generatePartNumber($part)
    {
        $categoryCode = strtoupper(substr($part->category ?: 'OTH', 0, 3));
        $manufacturerCode = strtoupper(substr($part->manufacturer ?: 'MFG', 0, 3));
        
        do {
            $random = strtoupper(\Illuminate\Support\Str::random(6));
            $partNumber = "PART{$categoryCode}{$manufacturerCode}{$random}";
        } while (self::where('part_number', $partNumber)->exists());
        
        return $partNumber;
    }

    /**
     * Validate part.
     *
     * @param  MaintenancePart  $part
     * @return void
     * @throws \Exception
     */
    private static function validatePart($part)
    {
        // Check if part number is unique
        if ($part->part_number) {
            $existingPart = self::where('part_number', $part->part_number)
                ->where('id', '!=', $part->id)
                ->first();
                
            if ($existingPart) {
                throw new \Exception('Part number already exists');
            }
        }
        
        // Validate quantity
        if ($part->quantity < 0) {
            throw new \Exception('Quantity cannot be negative');
        }
        
        // Validate unit price
        if ($part->unit_price && $part->unit_price < 0) {
            throw new \Exception('Unit price cannot be negative');
        }
        
        // Validate stock levels
        if ($part->minimum_stock < 0) {
            throw new \Exception('Minimum stock cannot be negative');
        }
        
        if ($part->reorder_level < 0) {
            throw new \Exception('Reorder level cannot be negative');
        }
        
        if ($part->minimum_stock > $part->reorder_level) {
            throw new \Exception('Minimum stock should be less than reorder level');
        }
        
        // Validate dates
        if ($part->purchase_date && $part->warranty_expiry) {
            if ($part->purchase_date > $part->warranty_expiry) {
                throw new \Exception('Purchase date must be before warranty expiry');
            }
        }
    }

    /**
     * Get parts that need reorder.
     *
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getNeedReorder($filters = [])
    {
        $cacheKey = 'maintenance_parts_need_reorder_' . md5(json_encode($filters));
        
        return Cache::remember($cacheKey, now()->addHour(), function () use ($filters) {
            $query = self::where('is_active', true)
                ->whereRaw('quantity <= reorder_level')
                ->orderBy('quantity', 'asc')
                ->orderBy('part_name', 'asc');
            
            // Apply filters
            if (isset($filters['category'])) {
                $query->where('category', $filters['category']);
            }
            
            if (isset($filters['supplier'])) {
                $query->where('supplier', $filters['supplier']);
            }
            
            return $query->get();
        });
    }

    /**
     * Get low stock parts.
     *
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getLowStock($filters = [])
    {
        $cacheKey = 'maintenance_parts_low_stock_' . md5(json_encode($filters));
        
        return Cache::remember($cacheKey, now()->addHour(), function () use ($filters) {
            $query = self::where('is_active', true)
                ->whereRaw('quantity <= minimum_stock')
                ->orderBy('quantity', 'asc')
                ->orderBy('part_name', 'asc');
            
            // Apply filters
            if (isset($filters['category'])) {
                $query->where('category', $filters['category']);
            }
            
            return $query->get();
        });
    }

    /**
     * Get part by part number.
     *
     * @param  string  $partNumber
     * @return MaintenancePart|null
     */
    public static function getByPartNumber($partNumber)
    {
        return Cache::remember("maintenance_part_number_{$partNumber}", now()->addHours(12), function () use ($partNumber) {
            return self::where('part_number', $partNumber)->first();
        });
    }

    /**
     * Get parts statistics.
     *
     * @param  array  $filters
     * @return array
     */
    public static function getStatistics($filters = [])
    {
        $query = self::query();
        
        // Apply filters
        if (isset($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        
        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }
        
        $totalParts = $query->count();
        $activeParts = $query->where('is_active', true)->count();
        $totalValue = $query->sum(DB::raw('quantity * unit_price'));
        $totalQuantity = $query->sum('quantity');
        
        // Stock status breakdown
        $outOfStock = $query->clone()->where('quantity', 0)->count();
        $lowStock = $query->clone()->where('quantity', '>', 0)
            ->whereRaw('quantity <= minimum_stock')->count();
        $reorderLevel = $query->clone()->where('quantity', '>', 0)
            ->whereRaw('quantity <= reorder_level')->count();
        $inStock = $query->clone()->where('quantity', '>', 0)
            ->whereRaw('quantity > reorder_level')->count();
        
        // Category breakdown
        $byCategory = $query->clone()
            ->selectRaw('category, COUNT(*) as count, SUM(quantity * unit_price) as value')
            ->groupBy('category')
            ->orderBy('value', 'desc')
            ->get()
            ->mapWithKeys(function($item) {
                return [$item->category => [
                    'count' => $item->count,
                    'value' => $item->value
                ]];
            })
            ->toArray();
        
        // Top used parts
        $topUsed = $query->clone()
            ->orderBy('usage_count', 'desc')
            ->limit(10)
            ->get();
        
        return [
            'total_parts' => $totalParts,
            'active_parts' => $activeParts,
            'total_value' => round($totalValue, 2),
            'total_quantity' => $totalQuantity,
            'average_value_per_part' => $totalParts > 0 ? round($totalValue / $totalParts, 2) : 0,
            'stock_status' => [
                'out_of_stock' => $outOfStock,
                'low_stock' => $lowStock,
                'reorder_level' => $reorderLevel,
                'in_stock' => $inStock
            ],
            'by_category' => $byCategory,
            'top_used_parts' => $topUsed
        ];
    }

    /**
     * Import parts from CSV.
     *
     * @param  array  $data
     * @param  User  $importer
     * @return MaintenancePart
     */
    public static function importFromCSV($data, $importer)
    {
        $part = new self($data);
        $part->created_by = $importer->id;
        $part->save();
        
        Log::info('Maintenance part imported from CSV', [
            'part_id' => $part->id,
            'part_number' => $part->part_number,
            'importer_id' => $importer->id
        ]);
        
        return $part;
    }

    /**
     * Export part data to array.
     *
     * @return array
     */
    public function exportToArray()
    {
        return [
            'id' => $this->id,
            'part_number' => $this->part_number,
            'part_name' => $this->part_name,
            'description' => $this->description,
            'category' => $this->category_display,
            'manufacturer' => $this->manufacturer,
            'supplier' => $this->supplier,
            'unit_price' => $this->unit_price,
            'quantity' => $this->quantity,
            'minimum_stock' => $this->minimum_stock,
            'reorder_level' => $this->reorder_level,
            'total_value' => $this->total_value,
            'location' => $this->location,
            'bin_number' => $this->bin_number,
            'serial_number' => $this->serial_number,
            'batch_number' => $this->batch_number,
            'purchase_date' => $this->purchase_date,
            'warranty_expiry' => $this->warranty_expiry,
            'last_used' => $this->last_used,
            'usage_count' => $this->usage_count,
            'usage_rate' => $this->usage_rate,
            'condition' => $this->condition_display,
            'is_consumable' => $this->is_consumable,
            'is_active' => $this->is_active,
            'stock_status' => $this->stock_status,
            'needs_reorder' => $this->needs_reorder,
            'is_warranty_valid' => $this->is_warranty_valid,
            'days_until_warranty_expiry' => $this->days_until_warranty_expiry,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at
        ];
    }

    /**
     * Scope a query to only include active parts.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include parts that need reorder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeNeedReorder($query)
    {
        return $query->whereRaw('quantity <= reorder_level');
    }

    /**
     * Scope a query to only include low stock parts.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeLowStock($query)
    {
        return $query->whereRaw('quantity <= minimum_stock');
    }

    /**
     * Scope a query to only include parts in a specific category.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $category
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope a query to only include parts from a specific supplier.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $supplier
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFromSupplier($query, $supplier)
    {
        return $query->where('supplier', $supplier);
    }

    /**
     * Scope a query to only include consumable parts.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeConsumable($query)
    {
        return $query->where('is_consumable', true);
    }

    /**
     * Scope a query to only include non-consumable parts.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeNonConsumable($query)
    {
        return $query->where('is_consumable', false);
    }
}