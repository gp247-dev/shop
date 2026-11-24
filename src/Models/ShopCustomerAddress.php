<?php
#GP247/Shop/Models/ShopCustomerAddress.php
namespace GP247\Shop\Models;

use Illuminate\Database\Eloquent\Model;

class ShopCustomerAddress extends Model
{
    use \GP247\Core\Models\ModelTrait;
    use \GP247\Core\Models\UuidTrait;

    protected $guarded    = [];
    /** @var array<string, string> address_meta holds the codes of the address (written by AddressService only). */
    protected $casts = ['address_meta' => 'array'];
    public $table = GP247_DB_PREFIX.'shop_customer_address';
    protected $connection = GP247_DB_CONNECTION;

    protected static function boot()
    {
        parent::boot();
        //Uuid
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = gp247_generate_id('CAD');
            }
        });
    }
}
