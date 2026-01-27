<?php


namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class TaxInformation extends Model
{
    protected $fillable = [
        'customer_id', 'razon_social', 'rfc', 'regimen_fiscal', 
        'codigo_postal', 'direccion', 'correo_facturacion', 'uso_cfdi'
    ];
}