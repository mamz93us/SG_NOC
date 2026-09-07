<?php

return [

    'title' => 'Add Ticketing Request',
    'close' => 'Close',
    'submit' => 'Submit Ticket',
    'submitting' => 'Submitting…',
    'cancel' => 'Cancel',
    'done' => 'Done',

    'fields' => [
        'category' => 'Category',
        'subcategory' => 'Sub Category',
        'title' => 'Ticket Title',
        'title_placeholder' => 'Briefly describe the issue',
        'description' => 'Ticket Description',
        'description_placeholder' => 'Provide as much detail as possible…',
    ],

    'category_options' => [
        'loading' => 'Loading…',
        'choose_category' => '-- Choose Category --',
        'no_categories' => 'No categories available',
        'load_failed' => 'Could not load categories',
        'choose_subcategory' => '-- Choose Sub Category --',
        'no_subcategories' => 'No sub-categories',
        'choose_category_first' => '-- Choose a category first --',
    ],

    'attachments' => [
        'heading' => 'Attachments',
        'upload_title' => 'Upload Supporting Documents',
        'limit' => 'Up to 3 files, 20 MB each',
        'allowed' => 'Allowed files [Images · Videos · PDF · Word · Excel]',
        'choose_file' => 'Choose File',
        'no_file_chosen' => 'No file chosen',
        'files_selected' => ':count of :max selected (:size)',
        'remove' => 'Remove :name',
    ],

    'errors' => [
        'system_unavailable' => 'The ticketing system is not reachable right now. Please contact IT directly.',
        'choose_category' => 'Please choose a category.',
        'choose_subcategory' => 'Please choose a sub category.',
        'enter_title' => 'Please give the ticket a title.',
        'enter_description' => 'Please describe the issue.',
        'not_added_prefix' => 'Not added: :list.',
        'file_too_large' => ':name is :size',
        'file_limit' => ':name (limit is :max)',
        'list_separator' => '; ',
        'submit_failed_retry' => 'The ticket could not be submitted. Please try again.',
    ],

    'success' => [
        'heading' => 'Ticket submitted',
        'reference_label' => 'Reference number',
        'reference_pending' => 'pending',
        'follow_up' => 'The IT team will follow up shortly.',
    ],

];
