(function () {
    'use strict';

    var form = document.getElementById('pdf-upload-form');
    if (!form) return;

    var fileInput = document.getElementById('pdf-file');
    var textInput = document.getElementById('pdf-extracted-text');
    var segmentsInput = document.getElementById('pdf-page-segments');
    var statusNode = document.getElementById('pdf-upload-status');
    var button = document.getElementById('pdf-upload-button');
    var maxBytes = parseInt(form.getAttribute('data-max-bytes') || '20971520', 10);
    var maxTextBytes = parseInt(form.getAttribute('data-max-text-bytes') || '2097152', 10);
    var submitting = false;

    function status(message) {
        if (statusNode) statusNode.textContent = message;
    }

    function fail(message) {
        submitting = false;
        status(message);
        if (button) button.disabled = false;
    }

    function cleanPageText(items) {
        var out = '';
        for (var i = 0; i < items.length; i++) {
            var item = items[i];
            if (typeof item.str !== 'string') continue;
            out += item.str + (item.hasEOL ? '\n' : ' ');
        }
        return out.replace(/[ \t]+\n/g, '\n').replace(/[ ]{2,}/g, ' ').trim();
    }

    form.addEventListener('submit', async function (event) {
        if (submitting) return;
        event.preventDefault();

        var file = fileInput && fileInput.files ? fileInput.files[0] : null;
        if (!file) {
            fail('Choose a PDF first.');
            return;
        }
        if (file.size > maxBytes) {
            fail('That PDF is larger than this server allows.');
            return;
        }
        if (!/\.pdf$/i.test(file.name) && file.type !== 'application/pdf') {
            fail('That file is not a PDF.');
            return;
        }

        var pdfjsLib = window.pdfjsLib;
        if (!pdfjsLib) {
            fail('The PDF reader did not load. Reload the page and try again.');
            return;
        }

        submitting = true;
        if (button) button.disabled = true;
        status('Reading PDF…');

        try {
            pdfjsLib.GlobalWorkerOptions.workerSrc = '/assets/vendor/pdfjs/pdf.worker.min.js';

            var buffer = await file.arrayBuffer();
            var task = pdfjsLib.getDocument({
                data: new Uint8Array(buffer),
                isEvalSupported: false,
                useSystemFonts: false,
            });
            var pdf = await task.promise;
            var pages = [];

            for (var pageNumber = 1; pageNumber <= pdf.numPages; pageNumber++) {
                status('Reading PDF… page ' + pageNumber + ' of ' + pdf.numPages);
                var page = await pdf.getPage(pageNumber);
                var content = await page.getTextContent();
                var pageText = cleanPageText(content.items);
                if (pageText !== '') pages.push({ page: pageNumber, text: pageText });
            }

            await pdf.destroy();

            var text = pages.map(function (entry) { return entry.text; }).join('\n\n').trim();
            if (text === '') {
                fail('No selectable text was found. Scanned PDFs need OCR, which is not available yet.');
                return;
            }

            if (new TextEncoder().encode(text).length > maxTextBytes) {
                fail('This PDF contains too much text for this upload.');
                return;
            }

            textInput.value = text;
            if (segmentsInput) segmentsInput.value = JSON.stringify(pages);
            status('Uploading…');
            form.submit();
        } catch (error) {
            var message = error && error.message ? error.message : 'Could not read that PDF.';
            fail('Could not read that PDF: ' + message);
        }
    });
})();
