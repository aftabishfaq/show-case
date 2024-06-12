<?php


namespace App\Helper;

use App\Models\User;
use App\Models\UserCode;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use function PHPUnit\Framework\isEmpty;
use function PHPUnit\Framework\isNull;

class CrudHelper
{
    public static function getModelClass($model)
    {
        $model = ucwords($model);
        $model_class = "App\\Models\\".$model;
        return [
            'model' => $model,
            'model_class' => $model_class
        ];
    }

    public static function getModelView($model)
    {
        return 'admin.'.Str::plural($model).'.show';
    }

    public static function updateOrCreateModel($model,$arr)
    {
        try {
            $operation = @$arr['id'] ? ' ' . __('admin.updated') : ' ' . __('admin.added');
            unset($arr['_token']);
            if(@$arr['image']){
                $imageName = time() . '.' . $arr['image']->getClientOriginalName();
                $imagePath = 'images/' . $imageName;
                Storage::disk('s3')->put($imagePath, file_get_contents($arr['image']));
                $arr['image'] = $imagePath;
            }
            $model_arr = self::getModelClass($model);
            $data = $model_arr['model_class']::updateOrCreate(['id'=>@$arr['id']],$arr);
            if(@$arr['model'] == 'App\Models\Package'){
                $model_arr['model'] = 'Package';
            }
            return [
                'success' => true,
                'data' => $data,
                'message' => $model_arr['model'].$operation
            ];
        }
        catch (\Exception $exception)
        {
            return [
                'success' => false,
                'message' => $exception->getMessage()
            ];
        }
    }

    public static function getModelById($model,$id)
    {
        $model_arr = self::getModelClass($model);
        $data = $model_arr['model_class']::find($id);
        if($data)
        {
            return [
                'success' => true,
                'data' => $data
            ];
        }

        return [
            'success' => false,
            'message' => $model_arr['model'].' not found!'
        ];
    }

    public static function deleteModelById($model,$id)
    {
        $model_arr = self::getModelClass($model);
        $data = $model_arr['model_class']::find($id);
        if(method_exists($data,'deleteAction'))
        {
            try {
                $data->deleteAction();
            }
            catch (\Exception $e)
            {

                return [
                    'success' => false,
                    'message' => $e->getMessage()
                ];
            }
        }
        if($data)
        {
            $data->delete();
            return [
                'success' => true,
                'message' => $model_arr['model'] . ' ' . __('admin.deleted') .'!'
            ];
        }

        return [
            'success' => false,
            'message' => $model_arr['model'].' not found!'
        ];
    }

    public static function getModelIdByName($model,$name,$column_name = null)
    {
        $model_arr = self::getModelClass($model);
        $column_name = $column_name == null ? 'name' : $column_name;
        $data = $model_arr['model_class']::where($column_name,$name)->first();
        if(!$data)
        {
            $data = self::updateOrCreateModel($model,[$column_name=>$name])['data'];
        }
        return [
            'success' => true,
            'data' => $data,
        ];
    }


    public static function priceNumberFormat($number){
        return number_format($number, 2, ',', '.');
    }

    public static function encode_ma_number($code)
    {
        $arr = str_split($code);
        $encoded = '';
        foreach ($arr as $value)
        {
            $encoded .= self::$encoding[$value];
        }
        return $encoded;
    }

    public static function decode_ma_number($encode)
    {
        $arr = str_split($encode,2);
        $decode = '';
        foreach ($arr as $value_1)
        {
            foreach (self::$encoding as $key => $value_2)
            {
                $decode .= ($value_1 == $value_2) ? $key : '';
            }
        }
        return $decode;
    }

}
