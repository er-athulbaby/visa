<?php

it('names upload fields in plain words when an upload fails', function () {
    $passport = __('validation.uploaded', ['attribute' => __('validation.attributes.passportScan')]);
    $photo = __('validation.uploaded', ['attribute' => __('validation.attributes.photo')]);

    expect($passport)->toBe("The passport file couldn't be uploaded. Check it is under 5 MB and try again.")
        ->and($photo)->toBe("The photo couldn't be uploaded. Check it is under 5 MB and try again.");
});

it('keeps Laravel\'s other validation messages', function () {
    expect(__('validation.required', ['attribute' => 'email']))->toBe('The email field is required.');
});
