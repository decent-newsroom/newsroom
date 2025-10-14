import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        pubkey: String
    };

    connect() {
        const pubkey = this.pubkeyValue;
        const topic = `/articles/${pubkey}`;
        const hubUrl = window.MercureHubUrl || (document.querySelector('meta[name="mercure-hub"]')?.content);
        console.log('[articles-mercure] connect', { pubkey, topic, hubUrl });
        if (!hubUrl) return;
        const url = new URL(hubUrl);
        url.searchParams.append('topic', topic);
        this.eventSource = new EventSource(url.toString());
        this.eventSource.onopen = () => {
          console.log('[articles-mercure] EventSource opened', url.toString());
        };


        this.eventSource.onmessage = this.handleMessage.bind(this);
    }

    disconnect() {
        if (this.eventSource) {
            this.eventSource.close();
        }
    }

    async handleMessage(event) {
        const data = JSON.parse(event.data);
        console.log(data);
        if (data.articles && data.articles.length > 0) {
            // Fetch the rendered HTML from the server
            try {
                const response = await fetch('/articles/render', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ articles: data.articles }),
                });
                const html = await response.text();
                // Prepend the new articles HTML to the article list
                const articleList = this.element.querySelector('.article-list');
                if (articleList) {
                    articleList.insertAdjacentHTML('afterbegin', html);
                }
            } catch (error) {
                console.error('Error fetching rendered articles:', error);
            }
        }
    }
}
