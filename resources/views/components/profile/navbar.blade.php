@props([
    'title' => __('Profile'),
    'subtitle' => __('Your account preferences'),
    'titleOnDesktop' => false,
])

<x-dashboard.navbar section="profile" :title="$title" :subtitle="$subtitle" :titleOnDesktop="$titleOnDesktop" />
