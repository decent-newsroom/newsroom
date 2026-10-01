(function () {
    'use strict';

    function slugify(value) {
        return value
            .toLowerCase()
            .trim()
            .replace(/[^\p{L}\p{N}\s-]/gu, '')
            .replace(/\s+/g, '-')
            .replace(/-+/g, '-') || 'section';
    }

    function buildTableOfContents() {
        var content = document.querySelector('[data-docs-content]');
        var toc = document.querySelector('[data-docs-toc]');

        if (!content || !toc) {
            return;
        }

        var headings = content.querySelectorAll('h2, h3');
        if (headings.length === 0) {
            return;
        }

        var usedIds = new Set();
        var list = document.createElement('ol');

        headings.forEach(function (heading) {
            var baseId = heading.id || slugify(heading.textContent || '');
            var id = baseId;
            var suffix = 2;

            while (usedIds.has(id) || (document.getElementById(id) && document.getElementById(id) !== heading)) {
                id = baseId + '-' + suffix;
                suffix += 1;
            }

            usedIds.add(id);
            heading.id = id;

            var item = document.createElement('li');
            item.className = 'docs-toc-level-' + heading.tagName.slice(1);
            var link = document.createElement('a');
            link.href = '#' + id;
            link.textContent = heading.textContent || id;
            item.appendChild(link);
            list.appendChild(item);
        });

        var title = document.createElement('h2');
        title.textContent = 'On this page';
        toc.appendChild(title);
        toc.appendChild(list);
        toc.hidden = false;
    }

    document.addEventListener('DOMContentLoaded', buildTableOfContents);
}());
