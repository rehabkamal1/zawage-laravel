<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = $this->user();
        $isMale = $user && $user->gender === 'male';

        $rules = [
            'full_name'      => 'required|string|max:255',
            'phone'          => 'required|string|max:20|unique:users,phone,' . ($user ? $user->id : ''),
            'dob'            => 'required|date',
            'governorate'    => 'required|string|max:100',
            'area'           => 'required|string|max:100',
            'education'      => 'required|string|max:255',
            'job'            => 'required|string|max:255',
            'prayer'         => 'required|string|max:50',
            // Optional / nullable for both
            'address'        => 'nullable|string',
            'income'         => 'nullable|string|max:100',
            'accommodation'  => 'nullable|string|max:100',
            'marital_status' => 'nullable|string|max:50',
            'bio'            => 'nullable|string',
            'weight'         => 'nullable|numeric',
            'height'         => 'nullable|numeric',
            'skin_tone'      => 'nullable|string|max:50',
        ];

        if ($isMale) {
            // Male: smoking required, hijab NOT applicable
            $rules['smoking'] = 'required|string|max:50';
        } else {
            // Female: hijab required, smoking optional
            $rules['hijab']          = 'required|string|max:50';
            $rules['smoking']        = 'nullable|string|max:50';
            // Guardian phone required for brides
            $rules['guardian_name']  = 'nullable|string|max:255';
            $rules['guardian_phone'] = 'required|string|max:20';
            $rules['relation']       = 'nullable|string|max:100';
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'full_name.required'      => 'الاسم الكامل مطلوب',
            'phone.required'          => 'رقم الهاتف مطلوب',
            'phone.unique'            => 'رقم الهاتف مستخدم بالفعل، يرجى إدخال رقم آخر',
            'dob.required'            => 'تاريخ الميلاد مطلوب',
            'governorate.required'    => 'المحافظة مطلوبة',
            'area.required'           => 'المنطقة مطلوبة',
            'education.required'      => 'المستوى التعليمي مطلوب',
            'job.required'            => 'المهنة مطلوبة',
            'prayer.required'         => 'الالتزام بالصلاة مطلوب',
            'hijab.required'          => 'نوع الحجاب مطلوب',
            'smoking.required'        => 'حالة التدخين مطلوبة',
            'guardian_phone.required' => 'رقم ولي الأمر مطلوب للعروسة',
        ];
    }
}
