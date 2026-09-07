<!-- ACCOUNT SETTINGS MODAL -->
<div id="account-settings-modal" class="fixed inset-0 z-[9999] flex items-center justify-center hidden p-4">
    <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" onclick="closeAccountSettings()"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-3xl mx-auto overflow-hidden flex flex-col max-h-[90vh]"
        style="font-family: 'Poppins', sans-serif;">

        <!-- Modal Header -->
        <div class="px-8 pt-8 pb-6 bg-gradient-to-r from-blue-500 to-indigo-600 flex-shrink-0">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-white text-xl font-bold tracking-wide">Account Settings</h2>
                <button onclick="closeAccountSettings()" class="text-white/70 hover:text-white transition-colors">
                    <i class="ri-close-line text-2xl"></i>
                </button>
            </div>
            <div class="flex items-center gap-3">
                <div
                    class="w-12 h-12 rounded-full flex items-center justify-center bg-white/20 overflow-hidden js-avatar-slot">
                    <i class="ri-user-line text-white text-xl"></i>
                </div>
                <div>
                    <p id="modal-display-name" class="text-white font-semibold text-sm">Loading...</p>
                    <span id="modal-role-badge"
                        class="inline-block px-3 py-0.5 rounded-full text-xs font-bold uppercase tracking-wider mt-1 bg-white/20 text-white/90 border border-white/30">—</span>
                </div>
            </div>
        </div>

        <!-- Tab Bar -->
        <div id="settings-tabs"
            class="flex items-center gap-1.5 px-6 sm:px-8 py-3 bg-gray-50 border-b border-gray-200 overflow-x-auto flex-shrink-0">
            <button type="button" class="settings-tab-btn active" data-tab="profile"
                onclick="switchSettingsTab('profile')">
                <i class="ri-user-3-line"></i><span>Profile</span>
            </button>
            <button type="button" class="settings-tab-btn" data-tab="security" onclick="switchSettingsTab('security')">
                <i class="ri-lock-2-line"></i><span>Security</span>
            </button>
            <button type="button" class="settings-tab-btn" data-tab="signature"
                onclick="switchSettingsTab('signature')">
                <i class="ri-quill-pen-line"></i><span>Signature</span>
            </button>
            <button type="button" class="settings-tab-btn" data-tab="teamcard" onclick="switchSettingsTab('teamcard')">
                <i class="ri-contacts-book-2-line"></i><span>Team Card</span>
            </button>
            <button type="button" class="settings-tab-btn" data-tab="google" onclick="switchSettingsTab('google')">
                <i class="ri-google-fill"></i><span>Google</span>
            </button>
        </div>

        <!-- Modal Body (scrollable) -->
        <div class="px-6 sm:px-8 py-6 overflow-y-auto flex-1">
            <form id="account-settings-form" onsubmit="saveAccountSettings(event)">

                <!-- ============================================================ -->
                <!-- PROFILE TAB -->
                <!-- ============================================================ -->
                <div class="settings-tab-panel" data-tab-panel="profile">

                    <div class="mb-4">
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Full
                            Name</label>
                        <input type="text" id="settings-fullname" name="full_name" required
                            class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm text-gray-800 focus:outline-none transition-all"
                            onfocus="this.style.borderColor='#3B82F6'" onblur="this.style.borderColor='#e5e7eb'"
                            placeholder="Enter your full name">
                    </div>

                    <div class="mb-6">
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Email
                            Address</label>
                        <input type="email" id="settings-email" name="email" autocomplete="username" required
                            class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm text-gray-800 focus:outline-none transition-all"
                            onfocus="this.style.borderColor='#3B82F6'" onblur="this.style.borderColor='#e5e7eb'"
                            placeholder="Enter your email">
                    </div>

                    <div class="mb-2">
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Profile
                            Picture</label>
                        <div class="flex items-center gap-4 mb-3">
                            <img id="avatar-preview" src=""
                                class="w-16 h-16 rounded-full object-cover border-2 border-gray-200 hidden">
                            <div id="avatar-preview-fallback"
                                class="w-16 h-16 rounded-full bg-gray-100 flex items-center justify-center">
                                <i class="ri-user-line text-2xl text-gray-400"></i>
                            </div>
                            <label for="avatar-upload"
                                class="cursor-pointer text-xs font-bold px-3 py-2 rounded-lg bg-blue-50 text-blue-600 hover:bg-blue-100">
                                Upload Photo
                            </label>
                            <input type="file" id="avatar-upload" name="profile_picture"
                                accept="image/png,image/jpeg,image/webp" class="hidden" onchange="previewAvatar(this)">
                        </div>

                        <div id="avatar-source-choice" class="hidden space-y-2">
                            <label class="flex items-center gap-2 text-sm text-gray-600">
                                <input type="radio" name="avatar_source" value="google"> Use my Google photo
                            </label>
                            <label class="flex items-center gap-2 text-sm text-gray-600">
                                <input type="radio" name="avatar_source" value="custom"> Use my uploaded photo
                            </label>
                        </div>
                    </div>

                </div>

                <!-- ============================================================ -->
                <!-- SECURITY TAB -->
                <!-- ============================================================ -->
                <div class="settings-tab-panel hidden" data-tab-panel="security">

                    <p class="text-xs text-gray-400 mb-5">Leave the password fields blank if you don't want to change
                        your password.</p>

                    <div class="mb-4">
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Current
                            Password <span class="text-red-400 normal-case font-normal">* required to change
                                password</span></label>
                        <div class="relative">
                            <input type="password" id="settings-current-password" name="current_password"
                                autocomplete="current-password"
                                class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm text-gray-800 focus:outline-none transition-all pr-12"
                                onfocus="this.style.borderColor='#3B82F6'" onblur="this.style.borderColor='#e5e7eb'"
                                placeholder="Enter your current password">
                            <button type="button" onclick="togglePassVis('settings-current-password','tog-icon-0')"
                                class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-700">
                                <i id="tog-icon-0" class="ri-eye-off-line text-lg"></i>
                            </button>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">New Password
                            <span class="text-gray-400 normal-case font-normal">(leave blank to keep
                                current)</span></label>
                        <div class="relative">
                            <input type="password" id="settings-password" name="new_password"
                                autocomplete="new-password"
                                class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm text-gray-800 focus:outline-none transition-all pr-12"
                                onfocus="this.style.borderColor='#3B82F6'" onblur="this.style.borderColor='#e5e7eb'"
                                placeholder="Enter new password">
                            <button type="button" onclick="togglePassVis('settings-password','tog-icon-1')"
                                class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-700">
                                <i id="tog-icon-1" class="ri-eye-off-line text-lg"></i>
                            </button>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Confirm New
                            Password</label>
                        <div class="relative">
                            <input type="password" id="settings-confirm-password" name="confirm_password"
                                autocomplete="new-password"
                                class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm text-gray-800 focus:outline-none transition-all pr-12"
                                onfocus="this.style.borderColor='#3B82F6'" onblur="this.style.borderColor='#e5e7eb'"
                                placeholder="Confirm new password">
                            <button type="button" onclick="togglePassVis('settings-confirm-password','tog-icon-2')"
                                class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-700">
                                <i id="tog-icon-2" class="ri-eye-off-line text-lg"></i>
                            </button>
                        </div>
                    </div>

                </div>

                <!-- ============================================================ -->
                <!-- SIGNATURE TAB -->
                <!-- ============================================================ -->
                <div class="settings-tab-panel hidden" data-tab-panel="signature">

                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">
                        E-Signature <span class="text-gray-400 normal-case font-normal">(PNG only, max 2MB)</span>
                    </label>

                    <div class="border-2 border-gray-200 rounded-xl overflow-hidden">
                        <!-- Preview existing -->
                        <div id="sig-preview-wrap" class="hidden">
                            <div
                                class="flex items-center justify-between px-4 py-2 bg-gray-50 border-b border-gray-200">
                                <span class="text-xs text-gray-400 uppercase tracking-wider font-semibold">Current
                                    Signature</span>
                                <span class="text-xs text-green-600 font-medium flex items-center gap-1">
                                    <i class="ri-checkbox-circle-fill"></i> Saved
                                </span>
                            </div>
                            <div class="flex items-center justify-center p-4 bg-white min-h-[80px]">
                                <img id="sig-preview-img" src="" alt="E-Signature"
                                    class="max-h-16 max-w-full object-contain">
                            </div>
                            <div class="h-px bg-gray-200"></div>
                        </div>

                        <!-- Upload input -->
                        <label for="sig-upload"
                            class="flex items-center gap-3 px-4 py-3 cursor-pointer hover:bg-blue-50 transition-all group">
                            <div
                                class="w-9 h-9 rounded-lg bg-blue-100 flex items-center justify-center flex-shrink-0 group-hover:bg-blue-200 transition-colors">
                                <i class="ri-upload-cloud-2-line text-lg text-blue-500"></i>
                            </div>
                            <div>
                                <span id="sig-filename"
                                    class="block text-sm text-gray-600 group-hover:text-blue-600 font-medium transition-colors">Click
                                    to upload PNG signature</span>
                                <span class="text-xs text-gray-400">Transparent background recommended</span>
                            </div>
                            <i
                                class="ri-arrow-right-s-line ml-auto text-gray-300 group-hover:text-blue-400 transition-colors"></i>
                        </label>
                    </div>
                    <input type="file" id="sig-upload" name="e_signature" accept=".png,image/png" class="hidden"
                        onchange="previewSignature(this)">

                </div>

                <!-- ============================================================ -->
                <!-- TEAM CARD TAB -->
                <!-- ============================================================ -->
                <div class="settings-tab-panel hidden" data-tab-panel="teamcard">

                    <div class="border-2 border-gray-200 rounded-xl p-4">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider">Show me on
                                    team card</label>
                                <p class="text-xs text-gray-400 mt-1">Appears on the website's "Meet the Team" section
                                </p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" id="settings-show-card" name="show_team_card" value="1"
                                    class="sr-only peer">
                                <div
                                    class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:bg-blue-500 transition-colors">
                                </div>
                                <div
                                    class="absolute left-1 top-1 w-4 h-4 bg-white rounded-full transition-transform peer-checked:translate-x-5">
                                </div>
                            </label>
                        </div>

                        <div class="mb-3">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Position
                                <span class="text-gray-400 normal-case font-normal">(optional)</span></label>
                            <input type="text" id="settings-position" name="position"
                                placeholder="e.g. Lead Interior Designer"
                                class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm text-gray-800 focus:outline-none transition-all"
                                onfocus="this.style.borderColor='#3B82F6'" onblur="this.style.borderColor='#e5e7eb'">
                        </div>

                        <div class="mb-3">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Contact
                                Number <span class="text-gray-400 normal-case font-normal">(optional)</span></label>
                            <input type="text" id="settings-contact-number" name="contact_number"
                                placeholder="0917 000 0000"
                                class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm text-gray-800 focus:outline-none transition-all"
                                onfocus="this.style.borderColor='#3B82F6'" onblur="this.style.borderColor='#e5e7eb'">
                        </div>

                        <div class="mb-3">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Gmail
                                <span class="text-gray-400 normal-case font-normal">(optional, can differ from login
                                    email)</span></label>
                            <input type="email" id="settings-social-gmail" name="social_gmail"
                                placeholder="name@gmail.com"
                                class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm text-gray-800 focus:outline-none transition-all"
                                onfocus="this.style.borderColor='#3B82F6'" onblur="this.style.borderColor='#e5e7eb'">
                        </div>

                        <div class="mb-4">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">WeChat ID
                                <span class="text-gray-400 normal-case font-normal">(optional)</span></label>
                            <input type="text" id="settings-social-wechat" name="social_wechat" placeholder="WeChat ID"
                                class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm text-gray-800 focus:outline-none transition-all"
                                onfocus="this.style.borderColor='#3B82F6'" onblur="this.style.borderColor='#e5e7eb'">
                        </div>

                        <div class="mb-4">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Viber
                                <span class="text-gray-400 normal-case font-normal">(optional)</span></label>
                            <input type="text" id="settings-social-viber" name="social_viber"
                                placeholder="0917 000 0000"
                                class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm text-gray-800 focus:outline-none transition-all"
                                onfocus="this.style.borderColor='#3B82F6'" onblur="this.style.borderColor='#e5e7eb'">
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <!-- WeChat: its own QR -->
                            <div>
                                <label
                                    class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">WeChat
                                    QR</label>
                                <div class="flex items-center gap-3">
                                    <img id="wechat-qr-preview" src=""
                                        class="w-14 h-14 rounded-lg object-cover border-2 border-gray-200 hidden">
                                    <div id="wechat-qr-preview-fallback"
                                        class="w-14 h-14 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0">
                                        <i class="ri-qr-code-line text-xl text-gray-400"></i>
                                    </div>
                                    <div class="flex flex-col gap-1">
                                        <label for="wechat-qr-upload"
                                            class="cursor-pointer text-xs font-bold px-3 py-1.5 rounded-lg bg-blue-50 text-blue-600 hover:bg-blue-100 text-center">
                                            Upload
                                        </label>
                                        <input type="file" id="wechat-qr-upload" name="wechat_qr_image"
                                            accept="image/png,image/jpeg,image/webp" class="hidden"
                                            onchange="previewPlatformQr(this, 'wechat')">
                                        <button type="button" id="wechat-qr-remove" onclick="removePlatformQr('wechat')"
                                            class="hidden text-xs font-bold px-3 py-1.5 rounded-lg bg-red-50 text-red-600 hover:bg-red-100">
                                            Remove
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Viber: its own QR -->
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Viber
                                    QR</label>
                                <div class="flex items-center gap-3">
                                    <img id="viber-qr-preview" src=""
                                        class="w-14 h-14 rounded-lg object-cover border-2 border-gray-200 hidden">
                                    <div id="viber-qr-preview-fallback"
                                        class="w-14 h-14 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0">
                                        <i class="ri-qr-code-line text-xl text-gray-400"></i>
                                    </div>
                                    <div class="flex flex-col gap-1">
                                        <label for="viber-qr-upload"
                                            class="cursor-pointer text-xs font-bold px-3 py-1.5 rounded-lg bg-blue-50 text-blue-600 hover:bg-blue-100 text-center">
                                            Upload
                                        </label>
                                        <input type="file" id="viber-qr-upload" name="viber_qr_image"
                                            accept="image/png,image/jpeg,image/webp" class="hidden"
                                            onchange="previewPlatformQr(this, 'viber')">
                                        <button type="button" id="viber-qr-remove" onclick="removePlatformQr('viber')"
                                            class="hidden text-xs font-bold px-3 py-1.5 rounded-lg bg-red-50 text-red-600 hover:bg-red-100">
                                            Remove
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- ============================================================ -->
                <!-- GOOGLE TAB -->
                <!-- ============================================================ -->
                <div class="settings-tab-panel hidden" data-tab-panel="google">

                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Google
                        Sign-In</label>
                    <div id="google-link-status"
                        class="border-2 border-gray-200 rounded-xl px-4 py-3 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <svg width="20" height="20" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg">
                                <path fill="#FFC107"
                                    d="M43.611,20.083H42V20H24v8h11.303c-1.649,4.657-6.08,8-11.303,8c-6.627,0-12-5.373-12-12c0-6.627,5.373-12,12-12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C12.955,4,4,12.955,4,24c0,11.045,8.955,20,20,20c11.045,0,20-8.955,20-20C44,22.659,43.862,21.35,43.611,20.083z" />
                                <path fill="#FF3D00"
                                    d="M6.306,14.691l6.571,4.819C14.655,15.108,18.961,12,24,12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C16.318,4,9.656,8.337,6.306,14.691z" />
                                <path fill="#4CAF50"
                                    d="M24,44c5.166,0,9.86-1.977,13.409-5.192l-6.19-5.238C29.211,35.091,26.715,36,24,36c-5.202,0-9.619-3.317-11.283-7.946l-6.522,5.025C9.505,39.556,16.227,44,24,44z" />
                                <path fill="#1976D2"
                                    d="M43.611,20.083H42V20H24v8h11.303c-0.792,2.237-2.231,4.166-4.087,5.571c0.001-0.001,0.002-0.001,0.003-0.002l6.19,5.238C36.971,39.205,44,34,44,24C44,22.659,43.862,21.35,43.611,20.083z" />
                            </svg>
                            <div>
                                <p id="google-link-label" class="text-sm font-medium text-gray-700">Loading…</p>
                                <p id="google-link-email" class="text-xs text-gray-400"></p>
                            </div>
                        </div>
                        <button type="button" id="google-link-btn" onclick="handleGoogleLinkClick()"
                            class="text-xs font-bold px-3 py-1.5 rounded-lg transition-colors">
                            ...
                        </button>
                    </div>

                </div>

                <!-- Save Button (always visible, applies to whichever tab was edited) -->
                <button type="submit" id="save-settings-btn"
                    class="w-full mt-6 py-3 rounded-xl text-white font-bold text-sm uppercase tracking-wider transition-all hover:opacity-90 active:scale-95 bg-gradient-to-r from-blue-500 to-indigo-600">
                    Save Changes
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Toast Notification Container (success / error messages) -->
<div id="settings-toast-container"
    class="fixed top-6 right-6 z-[10000] flex flex-col gap-3 items-end pointer-events-none"></div>