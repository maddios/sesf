<?php

use App\Http\Controllers\Admin\ShirtInventoryController;
use Illuminate\Support\Facades\Route;

// T-shirt stock, hand-outs and ad hoc sales (legacy TshirtList). Loaded by routes/web/admin.php.

Route::get('shirts/inventory', [ShirtInventoryController::class, 'index'])->name('shirts.inventory');
Route::put('shirts/inventory/{show}/stock', [ShirtInventoryController::class, 'updateStock'])->whereNumber('show')->name('shirts.stock');
Route::post('shirts/inventory/{show}/sales', [ShirtInventoryController::class, 'storeSale'])->whereNumber('show')->name('shirts.sales.store');
Route::delete('shirts/sales/{sale}', [ShirtInventoryController::class, 'destroySale'])->whereNumber('sale')->name('shirts.sales.destroy');
Route::post('shirts/{shirt}/handed-out', [ShirtInventoryController::class, 'handedOut'])->whereNumber('shirt')->name('shirts.handed-out');
