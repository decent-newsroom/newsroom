import {Controller} from '@hotwired/stimulus';
import Quill from 'quill';

import('quill/dist/quill.core.css');
import('quill/dist/quill.snow.css');


export default class extends Controller {

    connect() {
        const toolbarOptions = [
            ['bold', 'italic', 'strike'],
            ['link', 'blockquote', 'code-block', 'image'],
            [{ 'header': 1 }, { 'header': 2 }, { 'header': 3 }],
            [{ list: 'ordered' }, { list: 'bullet' }],
        ];

        const options = {
            theme: 'snow',
            modules: {
                toolbar: toolbarOptions,
            }
        }

        let quill = new Quill('#editor', options);
        let target = document.querySelector('#editor_content');


        quill.on('text-change', function(delta, oldDelta, source) {
            console.log('Text change!');
            console.log(delta);
            console.log(oldDelta);
            console.log(source);
            // save as html
            target.value = quill.root.innerHTML;
        });
    }

}
