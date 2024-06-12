<?php


namespace App\Helper;

class RequestHelper
{
    public static function ValidateModelRequest($model, $request)
    {
        $model = ucwords($model);
        $request_class = '\App\Http\Requests\Store'.$model.'Request';
        $x = new $request_class();
        $rules = $x->rules($request['id']);
        return $request->validate(($rules)  ? $rules : [],isset($request_class::$messages_arr) ? $request_class::$messages_arr : []);
    }
}
