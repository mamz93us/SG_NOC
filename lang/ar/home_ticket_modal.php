<?php

return [

    'title' => 'فتح طلب تذكرة',
    'close' => 'إغلاق',
    'submit' => 'إرسال التذكرة',
    'submitting' => 'جارٍ الإرسال…',
    'cancel' => 'إلغاء',
    'done' => 'تم',

    'fields' => [
        'category' => 'الفئة',
        'subcategory' => 'الفئة الفرعية',
        'title' => 'عنوان التذكرة',
        'title_placeholder' => 'صِف المشكلة باختصار',
        'description' => 'وصف التذكرة',
        'description_placeholder' => 'أضف أكبر قدر ممكن من التفاصيل…',
    ],

    'category_options' => [
        'loading' => 'جارٍ التحميل…',
        'choose_category' => '-- اختر الفئة --',
        'no_categories' => 'لا توجد فئات متاحة',
        'load_failed' => 'تعذّر تحميل الفئات',
        'choose_subcategory' => '-- اختر الفئة الفرعية --',
        'no_subcategories' => 'لا توجد فئات فرعية',
        'choose_category_first' => '-- اختر الفئة أولًا --',
    ],

    'attachments' => [
        'heading' => 'المرفقات',
        'upload_title' => 'رفع مستندات داعمة',
        'limit' => 'حتى 3 ملفات، بحد أقصى 20 ميجابايت لكل ملف',
        'allowed' => 'الملفات المسموح بها [صور · فيديوهات · PDF · Word · Excel]',
        'choose_file' => 'اختيار ملف',
        'no_file_chosen' => 'لم يتم اختيار أي ملف',
        'files_selected' => ':count من :max محددة (:size)',
        'remove' => 'إزالة :name',
    ],

    'errors' => [
        'system_unavailable' => 'نظام التذاكر غير متاح حاليًا. يرجى التواصل مع الدعم الفني مباشرة.',
        'choose_category' => 'يرجى اختيار فئة.',
        'choose_subcategory' => 'يرجى اختيار فئة فرعية.',
        'enter_title' => 'يرجى إعطاء التذكرة عنوانًا.',
        'enter_description' => 'يرجى وصف المشكلة.',
        'not_added_prefix' => 'لم تتم إضافة: :list.',
        'file_too_large' => ':name حجمه :size',
        'file_limit' => ':name (الحد الأقصى :max)',
        'list_separator' => '، ',
        'submit_failed_retry' => 'تعذّر إرسال التذكرة. يرجى المحاولة مرة أخرى.',
    ],

    'success' => [
        'heading' => 'تم إرسال التذكرة',
        'reference_label' => 'رقم المرجع',
        'reference_pending' => 'قيد التنفيذ',
        'follow_up' => 'سيتواصل معك فريق تقنية المعلومات قريبًا.',
    ],

];
