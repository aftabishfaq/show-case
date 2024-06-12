<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use HasFactory;
    protected $fillable = ['user_id', 'product_id', 'project_id', 'total_price', 'product_price', 'additional_charges', 'expression_count', 'payment_link', 'payment_status', 'payment_mode', 'instructions', 'sub_handle', 'next_period_start', 'trial_start', 'trial_end', 'in_trial', 'is_cancelled', 'refund_reason', 'amalienborg_gift_expiry_date', 'amalienborg_gift_used_date'];

    public function soldier()
    {
        return $this->belongsTo(User::class,'user_id','id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class,'product_id','id');
    }

    public function upgrade_from_product()
    {
        return $this->belongsTo(Product::class,'previously_subscribed_package','id');
    }

    public function photos()
    {
        return $this->hasMany(Photo::class);
    }

    public function getPictureCode()
    {
        return $this->soldier->getPictureCode();
    }

    public function payed_amount()
    {
        if ($this->payment_mode == 'full-payment')
        {
            return $this->payment_status ? $this->product->discount_price : 0;
        }
        else
        {
            return Payment::where('subscription_id',$this->id)->where('payment_status',1)->sum('price');
        }
    }

    public function payed_payments()
    {
        return Payment::where('subscription_id',$this->id)->where('payment_status',1)->count('id');
    }

    public function waivers()
    {
        return $this->hasMany(Waiver::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function unpayed_payments()
    {
        return Payment::where('subscription_id',$this->id)->where('payment_status',0)->count('id');
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function get_order_text()
    {
        $product = Product::find($this->product_id);
        if ($product->parent_id !== 0)
        {
            $product = Product::find($product->parent_id);
        }
        return $product->order_text;
    }

    public function bookOrders()
    {
        return $this->hasMany(BookOrder::class);
    }

}
