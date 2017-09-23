import { inject } from 'aurelia-framework';
import { HttpClient, json } from 'aurelia-fetch-client';

@inject(HttpClient, 'apiRoot', 'apiToken')
export class DataRepository {

    constructor(httpClient, apiRoot, apiToken) {
        this.httpClient = httpClient;
        this.apiRoot = apiRoot;
        this.apiToken = apiToken
    }

    getFrameworks() {
        var promise = new Promise((resolve, reject) => {
            // if (!this.frameworks) {
            this.httpClient.fetch(this.apiRoot + 'tables/frameworks/rows?order[Title]&status=1&access_token=' + this.apiToken)
                .then(response => response.json())
                .then(frameworksTable => {
                    this.frameworks = frameworksTable.data;
                    resolve(this.frameworks);
                }).catch(err => reject(err));
            // } else {
            // 	resolve(this.frameworks);
            // }
        });
        return promise;
    }

    getFramework(id) {
        var promise = new Promise((resolve, reject) => {
            this.httpClient.fetch(this.apiRoot + 'tables/frameworks/rows/' + id + '?status=1&access_token=' + this.apiToken)
                .then(response => response.json())
                .then(frameworksTable => {
                    this.framework = frameworksTable.data;
                    resolve(this.framework);
                }).catch(err => reject(err));
        });
        return promise;
    }

    addFramework(framework) {
        framework.active = 1;
        var promise = new Promise((resolve, reject) => {
            this.httpClient.fetch(this.apiRoot + 'tables/frameworks/rows/?access_token=' + this.apiToken, {
                    method: 'POST',
                    body: json(framework)
                })
                .then(response => response.json())
                .then(frameworkRecord => {
                    this.framework = frameworkRecord.data;
                    resolve(this.framework)
                }).catch(err => reject(err));
        });
        return promise;
    }

    deleteFramework(id) {
        var promise = new Promise((resolve, reject) => {
            this.httpClient.fetch(this.apiRoot + 'tables/frameworks/rows/' + id + '?status=1&access_token=' + this.apiToken, {
                    method: 'DELETE'
                })
                .then(response => response.json())
                .then(() => resolve(this.framework))
                .catch(err => reject(err));
        });
        return promise;
    }

    addFile(file) {
    	readFile(file).then(fileToUpload => {
            var theFile = {
                name: fileToUpload.name,
                title: 'my file',
                data: fileToUpload.data
            }
            var promise = new Promise((resolve, reject) => {
                this.httpClient.fetch(this.apiRoot + 'files?access_token=' + this.apiToken, {
                        method: 'POST',
                        body: fileToUpload
                    })
                    .then(response => {
                    	response.json()
                    })
                    .then(() => resolve(this.framework))
                    .catch(err => {
                    	reject(err)
                    });
            });    		
    	});
    }
}

const readFile = (file) => {
    let reader = new FileReader();

    return new Promise((resolve, reject) => {
        reader.onload = (event) => {
            file.data = event.target.result;
            resolve(file);
        };

        reader.onerror = () => {
            return reject(this);
        };

        if (/^image/.test(file.type)) {
            reader.readAsDataURL(file);
        } else {
            reader.readAsText(file);
        }

    })

};
