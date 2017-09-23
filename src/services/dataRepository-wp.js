import {inject} from 'aurelia-framework';
import {HttpClient, json} from 'aurelia-fetch-client';

@inject(HttpClient, 'apiRoot', 'apiToken')
export class DataRepository {

	constructor(httpClient, apiRoot, apiToken) {
		this.httpClient = httpClient;
		this.apiRoot = apiRoot;
		this.apiToken = apiToken
	}

	getFrameworks() {
		var promise = new Promise((resolve, reject) => {
			this.httpClient.fetch('http://cms.fullstack.dev/wp-json/wp/v2/frameworks')
				.then(response => response.json())
				.then(frameworks =>  {
					this.frameworks = frameworks;
					resolve(this.frameworks);
				}).catch(err => reject(err));
		});
		return promise;
	}

	getFramework(id) {
		var promise = new Promise((resolve, reject) => {
			this.httpClient.fetch(this.apiRoot +  'tables/frameworks/rows/' + id + '?status=1&access_token=' + this.apiToken)
				.then(response => response.json())
				.then(frameworksTable =>  {
					this.framework = frameworksTable.data;
					resolve(this.framework);
				}).catch(err => reject(err));
		});
		return promise;
	}

	addFramework(framework) {
		framework.active = 1;
		var promise = new Promise((resolve, reject) => {
			this.httpClient.fetch(this.apiRoot +  'tables/frameworks/rows/?access_token=' + this.apiToken, {
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
			this.httpClient.fetch(this.apiRoot +  'tables/frameworks/rows/' + id + '?status=1&access_token=' + this.apiToken, {
				method: 'DELETE'
			})
				.then(response => response.json())
				.then(() =>  resolve(this.framework))
				.catch(err => reject(err));
		});
		return promise;
	}

}