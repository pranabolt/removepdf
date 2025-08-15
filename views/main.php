<?php
$title = 'Remove Password from PDF — Fast, Private PDF unlocker';
$desc = 'Unlock your own password-protected PDF securely. No signup.';
$includeAlpine = true;
require_once __DIR__ . '/partials/header.php';
?>
        <div class="flex flex-col lg:flex-row lg:gap-12 xl:gap-16 items-start" x-data="uploader()">
          <div class="flex-1 lg:max-w-xl order-2 lg:order-1">
            <div class="inline-flex items-center gap-2 text-xs px-2 py-1 rounded-full bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200">
              Private • Fast • No installations
            </div>
            <h1 class="mt-3 text-3xl md:text-4xl font-semibold tracking-tight">Unlock your password‑protected PDF in seconds</h1>
            <p class="mt-3 text-slate-600">
              Upload a PDF you own, enter its password, and get a clean, unlocked copy—fast and private.
              We don't crack files—you must know the password.
            </p>
            <ul class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm text-slate-700">
<li class="flex items-center gap-2"><span class="w-5 h-5 rounded-full bg-emerald-100 text-emerald-700 grid place-items-center text-xs">✓</span> Files erased automatically after processing</li> <li class="flex items-center gap-2"><span class="w-5 h-5 rounded-full bg-emerald-100 text-emerald-700 grid place-items-center text-xs">✓</span> Passwords never saved or logged</li> <li class="flex items-center gap-2"><span class="w-5 h-5 rounded-full bg-emerald-100 text-emerald-700 grid place-items-center text-xs">✓</span> Works instantly — no account needed</li> <li class="flex items-center gap-2"><span class="w-5 h-5 rounded-full bg-emerald-100 text-emerald-700 grid place-items-center text-xs">✓</span> Handles large PDFs with ease</li>
            </ul>
            <!-- CTA pointing to main form -->
            <div class="mt-6">
              <button @click="$refs.file && $refs.file.click()" 
                      class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-slate-900 text-white hover:bg-slate-800 transition">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                Choose Your PDF to Unlock
              </button>
              <template x-if="fileName">
                <div class="mt-2 text-sm font-medium text-indigo-700">
                  Selected: <span x-text="fileName"></span>
                </div>
              </template>
              <ul class="mt-4 space-y-2 text-sm text-slate-700">
                <li class="flex items-start gap-3">
                  <span class="inline-flex items-center justify-center h-6 w-6 rounded-full bg-emerald-100">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
                  </span>
                  <span>Only unlock PDFs you own or have permission to modify.</span>
                </li>
                <li class="flex items-start gap-3">
                  <span class="inline-flex items-center justify-center h-6 w-6 rounded-full bg-emerald-100">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
                  </span>
                  <span>Don’t upload illegal or sensitive documents you’re not authorized to process.</span>
                </li>
                <li class="flex items-start gap-3">
                  <span class="inline-flex items-center justify-center h-6 w-6 rounded-full bg-emerald-100">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
                  </span>
                  <span>Follow your local laws and your organization’s policies.</span>
                </li>
              </ul>
            </div>
            <div class="mt-6 flex gap-3"></div>
          </div>

          <!-- APP CARD -->
          <div class="flex-1 lg:max-w-md order-1 lg:order-2 mb-8 lg:mb-0">
            <div class="sticky top-8">
            <form x-ref="form" class="space-y-4" @submit.prevent="submit()">
              <input type="hidden" name="action" value="process" />
              <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES); ?>" />

              <!-- Dropzone -->
              <div class="space-y-2">
                <label class="block text-sm font-medium">Your PDF</label>
                <div class="relative border-2 border-dashed rounded-xl p-6 transition-colors border-slate-300 bg-white hover:bg-slate-50"
                     @dragover.prevent="drag=true" 
                     @dragleave.prevent="drag=false" 
                     @drop.prevent="onDrop($event)" 
                     @click="$refs.file && $refs.file.click()"
                     :class="drag ? 'border-indigo-500 bg-indigo-50' : 'cursor-pointer'">
                  
                  <input id="file-input-main" name="pdf" type="file" x-ref="file" @change="onFile()" accept="application/pdf" class="sr-only"/>
                  
                  <div class="text-center">
                    <label for="file-input-main" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-900 text-white hover:bg-slate-800 cursor-pointer transition-colors">
                      <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                      Choose PDF
                    </label>
                    <p class="mt-2 text-sm text-slate-600">or drag & drop your .pdf here</p>
                    <p class="mt-1 text-xs text-slate-500">PDF files only</p>
                  </div>
                  
                  <template x-if="fileName">
                    <div class="mt-3 p-2 bg-indigo-50 rounded-lg text-center">
                      <div class="text-sm font-medium text-indigo-700" x-text="fileName"></div>
                    </div>
                  </template>
                </div>
              </div>

              <!-- Password -->
              <div>
                <label class="block text-sm font-medium mb-1">Password</label>
                <input type="password" name="password" x-ref="pwd" minlength="1" required placeholder="Enter the PDF password" 
                       autocomplete="new-password" autocapitalize="none" spellcheck="false"
                       class="w-full h-10 px-3 text-sm bg-white border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"/>
                <p class="mt-1 text-xs text-slate-500">Used only to unlock your file during processing, then discarded.</p>
              </div>

              <!-- Actions -->
              <div>
                <div class="flex items-center gap-2">
                  <button type="button" @click="reset()" :disabled="busy"
                          class="px-3 py-2 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50 disabled:opacity-50 transition-colors">
                    Clear
                  </button>
                  <button type="submit" :disabled="busy || !file"
                          class="flex-1 px-4 py-2 rounded-lg font-medium transition-all inline-flex items-center justify-center bg-gradient-to-r from-indigo-600 to-indigo-500 text-white hover:from-indigo-500 hover:to-indigo-400 disabled:opacity-60 disabled:cursor-not-allowed"
                          :class="busy ? 'bg-slate-300 text-slate-600' : ''">
                    <span x-text="busy ? 'Processing…' : 'Unlock PDF'">Unlock PDF</span>
                  </button>
                </div>
              </div>

              <template x-if="downloadUrl">
                  <div class="mt-2 border border-emerald-200 bg-emerald-50 text-emerald-800 rounded-lg p-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                  <div class="flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
                    <span class="text-sm">Your unlocked PDF is ready.</span>
                  </div>
                  <a :href="downloadUrl" class="text-sm inline-flex items-center justify-center px-3 py-1.5 rounded-md bg-emerald-600 text-white hover:bg-emerald-500">Download file</a>
                </div>
              </template>

              <template x-if="error">
                <div class="text-sm text-rose-700" x-text="error"></div>
              </template>

              <div class="text-sm text-slate-700">
                <h4 class="text-base font-semibold mt-2">How To Unlock a PDF Online?</h4>
                <ul class="mt-2 space-y-2">
                  <li class="flex items-start gap-3">
                    <span class="inline-flex items-center justify-center h-6 w-6 rounded-full bg-emerald-100">
                      <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
                    </span>
                    <span>Drop the locked PDF into the secure unlock tool.</span>
                  </li>
                  <li class="flex items-start gap-3">
                    <span class="inline-flex items-center justify-center h-6 w-6 rounded-full bg-emerald-100">
                      <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
                    </span>
                    <span>Enter the password to open the file.</span>
                  </li>
                  <li class="flex items-start gap-3">
                    <span class="inline-flex items-center justify-center h-6 w-6 rounded-full bg-emerald-100">
                      <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
                    </span>
                    <span>Instantly download or share the unlocked PDF.</span>
                  </li>
                  <li class="flex items-start gap-3">
                    <span class="inline-flex items-center justify-center h-6 w-6 rounded-full bg-emerald-100">
                      <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
                    </span>
                    <span>Fast. Private. Hassle-free.</span>
                  </li>
                </ul>
              </div>

              <!-- Plan messaging removed: fully free for now -->
            </form>
            </div>
          </div>
        </div>
<?php require_once __DIR__ . '/partials/footer.php'; ?>

  <script>
    function uploader() {
      return {
  // Alignment for the action row: 'left' | 'center' | 'right'
  align: 'left',
        drag: false,
        file: null,
        fileName: '',
        busy: false,
        progress: 0,
        status: '',
        error: '',
        downloadUrl: '',
  showBenefits: false,

        reset() {
          if (this.$refs.form) this.$refs.form.reset();
          if (this.$refs.file) this.$refs.file.value = '';
          if (this.$refs.pwd) this.$refs.pwd.value = '';
          this.drag = false;
          this.file = null; this.fileName = ''; this.error = ''; this.downloadUrl = ''; this.progress = 0; this.busy = false; this.status = '';
        },
        onFile() {
          const f = this.$refs.file.files[0];
          if (!f) { this.file = null; this.fileName = ''; return; }
          if (!f.name.toLowerCase().endsWith('.pdf')) {
            this.error = 'Only PDF files are allowed.'; this.$refs.file.value = ''; this.file = null; this.fileName = ''; return;
          }
          // Optional soft guard: extremely large files may fail in browser limits
          // Optional: client-side soft limit removed to avoid surfacing server numbers
          this.error = ''; this.file = f; this.fileName = f.name;
          // focus password prompt to guide user next
          this.$nextTick(() => { if (this.$refs.pwd) this.$refs.pwd.focus(); });
        },
        onDrop(e) {
          this.drag = false;
          const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
          if (!f) return;
          if (!f.name.toLowerCase().endsWith('.pdf')) { this.error = 'Only PDF files are allowed.'; this.file = null; this.fileName = ''; return; }
          // Optional: client-side soft limit removed to avoid surfacing server numbers
          this.error = ''; this.file = f; this.fileName = f.name;
          this.$nextTick(() => { if (this.$refs.pwd) this.$refs.pwd.focus(); });
        },
        async submit() {
          if (this.busy || !this.file) return;
          this.error = ''; this.downloadUrl = ''; this.status = 'Uploading…'; this.busy = true; this.progress = 20;

          // Build FormData and POST to this same file (action=process)
          const fd = new FormData(this.$refs.form);
          fd.set('pdf', this.file);

          try {
            const res = await fetch('index.php', { method: 'POST', body: fd });
            this.progress = 60; this.status = 'Processing…';
            const data = await res.json();
            this.progress = 90;

            if (!res.ok || !data.success) {
              this.error = data && data.message ? data.message : 'Failed to process file.';
              this.status = ''; this.busy = false; this.progress = 0; return;
            }

            this.status = 'Done.'; this.progress = 100;
            this.downloadUrl = data.download_url;
          } catch (e) {
            this.error = 'Network error. Try again.'; this.status = '';
          } finally {
            this.busy = false;
          }
        }
      }
    }

    // Sanity self-test (catches syntax issues in production quickly)
    (function () {
      try {
        const u = uploader();
        ['align','drag','file','fileName','busy','progress','status','error','downloadUrl','reset','onFile','onDrop','submit']
          .forEach(k => { if (!(k in u)) throw new Error('Missing: ' + k) });
      } catch (e) { /* no-op */ }
    })();
  </script>