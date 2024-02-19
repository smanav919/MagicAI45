@php
    $chatbot = App\Models\ChatBot::where('id', $settings_two->chatbot_template)->first();
    if ( $chatbot == null ){return;}
    $ipAddress = isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? $_SERVER['HTTP_CF_CONNECTING_IP'] : request()->ip();
    $db_ip_address = App\Models\RateLimit::where('ip_address', $ipAddress)
        ->where('type', 'chatbot')
        ->first();
    $chatbot_history = App\Models\ChatBotHistory::where('ip', $ipAddress)->first();
    $position = $settings_two->chatbot_position ?? 'bottom-left';
    $chatbot_custom_dimensions = '';
    switch ($position) {
        case 'top-left':
            $trigger_class = 'top-20 start-7';
            $chat_class = 'start-0 top-full';
            break;

        case 'top-right':
            $trigger_class = 'top-20 end-12';
            $chat_class = 'end-0 top-full';
            break;

        case 'bottom-right':
            $trigger_class = 'bottom-20 end-28';
            $chat_class = 'end-0 bottom-full';
            break;

        case 'bottom-left':
            $trigger_class = 'bottom-20 start-7';
            $chat_class = 'start-0 bottom-full';
            break;
    }
    if ($chatbot->width || $chatbot->height) {
        $chatbot_custom_dimensions = sprintf('%s %s', $chatbot->width ? 'width:' . $chatbot->width . '!important;' : '', $chatbot->height ? 'height:' . $chatbot->height . '!important;' : '');
    }
@endphp

<div class="fixed z-50 {{ $trigger_class }}">
    {{-- trigger --}}
    <div id="chatbot-trigger" class="w-14 h-14 p-0 rounded-full shadow-lg cursor-pointer overflow-hidden"
        data-chatbot="{{ $chatbot_history != null ? $chatbot_history->user_openai_chat_id : null }}">
        <img class="rounded-full overflow-hidden" src="{{ $chatbot->image ?? '/assets/img/chat-default.jpg' }}"
            alt="">
    </div>
    {{-- chat --}}
    <div id="chatbot-wrapper" style="{{ $chatbot_custom_dimensions }}"
        class="{{ $chat_class }} w-80 h-96 absolute flex flex-col !mb-4 translate-y-2 transition-all bg-[--tblr-body-bg] shadow-[0_3px_12px_rgba(0,0,0,0.08)] rounded-md text-heading font-medium text-sm
    before:h-4 before:absolute before:inset-x-0 before:-bottom-4
    opacity-0 invisible
  dark:bg-zinc-800">
        <div
            class="flex flex-col p-3 border-solid border-[--tblr-border-color] border-t-0 border-r-0 border-l-0 border-b">
            <div class="font-semibold">{{ $chatbot->title }}</div>
            <div class="text-xs">{{ $chatbot->role }}</div>
        </div>
        <div id="chatbot-messages" class="flex flex-col grow">
            <div class="chats-container h-60 p-3 overflow-scroll grow">
            </div>
            <form
                class="sticky bottom-0 z-10 flex w-full items-end gap-2 self-end bg-[--tblr-body-bg] p-3 py-[1.5rem] max-sm:items-end"
                id="chatbot_form">
                <input type="hidden" id="chatbot_category_id"
                    value="{{ $chatbot_history != null ? $chatbot_history->openai_chat_category_id : null }}">
                <input type="hidden" id="chatbot_chat_id"
                    value="{{ $chatbot_history != null ? $chatbot_history->user_openai_chat_id : null }}">
                <input type="hidden" id="chatbot" value="1">

                <div class="form-control flex flex-col min-h-[40px] rounded-[26px] p-0 max-sm:min-h-[45px]">
                    <div class="relative flex items-center grow">
                        <textarea
                            class="text-heading m-0 px-2 w-full border-none bg-transparent outline-none focus:border-none focus:ring-0 max-sm:pe-2 max-sm:ps-0 max-sm:text-[16px] max-sm:max-h-[120px]"
                            placeholder="{{ __('Type a message') }}" name="chatbot_prompt" id="chatbot_prompt" rows="1"></textarea>
                    </div>
                </div>
                <button id="send_chatbot_message_button"
                    class="btn btn-primary h-[40px] w-[40px] shrink-0 rounded-full p-0 max-sm:h-10 max-sm:w-10">
                    <svg class="rtl:-scale-x-100" width="16" height="14" viewBox="0 0 16 14" fill="currentColor"
                        xmlns="http://www.w3.org/2000/svg">
                        <path d="M0.125 14V8.76172L11.375 7.25L0.125 5.73828V0.5L15.875 7.25L0.125 14Z" />
                    </svg>
                </button>
            </form>
        </div>
    </div>
</div>
<template id="chat_user_bubble">
    <div class="lqd-chat-user-bubble mb-2 flex flex-row-reverse content-end gap-[8px] lg:ms-auto">
        <span class="text-dark">
            <span class="avatar h-[24px] w-[24px] shrink-0"
                style="background-image: url(/{{ auth()->check() ? Auth::user()->avatar : null }})"></span>
        </span>
        <div
            class="mb-[7px] max-w-[calc(100%-64px)] rounded-[2em] border-none bg-[#F3E2FD] text-[#090A0A] dark:bg-[rgba(var(--tblr-primary-rgb),0.3)] dark:text-white">
            <div class="chat-content px-[1.5rem] py-[0.75rem]">
            </div>
        </div>
    </div>
</template>
<template id="chat_ai_bubble">
    <div class="lqd-chat-ai-bubble group mb-2 flex content-start gap-[8px]">
        <span class="text-dark">
            <span class="avatar h-[24px] w-[24px] shrink-0"
                style="background-image: url('/{{ $chat->category->image ?? 'assets/img/auth/default-avatar.png' }}')"></span>
        </span>
        <div
            class="chat-content-container group-[&.loading]:before:animate-pulse-intense relative mb-[7px] min-h-[44px] max-w-[calc(100%-64px)] rounded-[2em] border-none text-[#090A0A] before:absolute before:inset-0 before:inline-block before:rounded-[2em] before:bg-[#E5E7EB] before:content-[''] dark:text-white dark:before:bg-[rgba(255,255,255,0.02)]">
            <div
                class="lqd-typing !inline-flex !items-center !gap-3 !rounded-full !px-3 !py-2 !font-medium !leading-none">
                <div class="lqd-typing-dots !flex !items-center !gap-1">
                    <span class="lqd-typing-dot !h-1 !w-1 !rounded-full"></span>
                    <span class="lqd-typing-dot !h-1 !w-1 !rounded-full"></span>
                    <span class="lqd-typing-dot !h-1 !w-1 !rounded-full"></span>
                </div>
            </div>
            <div class="">
                {{-- <div class="loader_image loader_image_bubble lqd-typing lqd-typing-loader">
                </div> --}}
                <pre
                    class="chat-content relative m-0 w-full whitespace-pre-wrap bg-transparent px-[1.5rem] py-[0.75rem] indent-0 font-[inherit] text-[1em] text-inherit empty:!hidden"></pre>
                <button
                    class="lqd-clipboard-copy pointer-events-auto invisible absolute -end-5 bottom-0 inline-flex h-10 w-10 items-center justify-center rounded-full border-none bg-white p-0 text-black opacity-0 !shadow-lg transition-all hover:-translate-y-[2px] hover:scale-110 group-hover:!visible group-hover:!opacity-100"
                    title="{{ __('Copy to clipboard') }}"
                    data-copy-options='{ "content": ".chat-content", "contentIn": "<.chat-content-container" }'>
                    <span class="sr-only">{{ __('Copy to clipboard') }}</span>
                    <svg xmlns="http://www.w3.org/2000/svg" height="20" viewBox="0 96 960 960" fill="currentColor"
                        width="20">
                        <path
                            d="M180 975q-24 0-42-18t-18-42V312h60v603h474v60H180Zm120-120q-24 0-42-18t-18-42V235q0-24 18-42t42-18h440q24 0 42 18t18 42v560q0 24-18 42t-42 18H300Zm0-60h440V235H300v560Zm0 0V235v560Z" />
                    </svg>
                </button>
            </div>
        </div>
</template>
