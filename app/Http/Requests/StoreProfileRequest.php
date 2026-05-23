<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'full_name'      => 'required|string|max:255',
            'phone'          => 'required|string|max:20',
            'dob'            => 'required|date',
            'governorate'    => 'required|string|max:100',
            'area'           => 'required|string|max:100',
            'address'        => 'required|string',
            'education'      => 'required|string|max:255',
            'job'            => 'required|string|max:255',
            'income'         => 'required|string|max:100',
            'accommodation'  => 'required|string|max:100',
            'prayer'         => 'required|string|max:50',
            'hijab'          => 'required|string|max:50',
            'smoking'        => 'required|string|max:50',
        ];
    }

    /**
     * Get custom error messages for validation failures.
     */
    public function messages(): array
    {
        return [
            'full_name.required'      => 'الاسم الكامل مطلوب',
            'phone.required'          => 'رقم الهاتف مطلوب',
            'dob.required'            => 'تاريخ الميلاد مطلوب',
            'governorate.required'    => 'المحافظة مطلوبة',
            'area.required'           => 'المنطقة مطلوبة',
            'address.required'        => 'العنوان مطلوب',
            'education.required'      => 'المستوى التعليمي مطلوب',
            'job.required'            => 'المهنة مطلوبة',
            'income.required'         => 'الدخل مطلوب',
            'accommodation.required'  => 'نوع السكن مطلوب',
            'prayer.required'         => 'نوع الصلاة مطلوب',
            'hijab.required'          => 'حالة الحجاب مطلوبة',
            'smoking.required'        => 'حالة التدخين مطلوبة',
        ];
    }
}
