import { inject, observable } from 'aurelia-framework';
import { DataRepository } from 'services/dataRepository';

@inject(DataRepository)
export class App {

	configureRouter(config, router) {

		this.router = router;
		config.title = "Fullstack";
		config.options.pushState = true;
		config.options.root = "/";
		config.map([
			{ route: ['','frameworks'], title: 'Frameworks', name: 'frameworks', moduleId: 'frameworks', nav: true },
			{ route: 'addframework', title: 'Add framework', name: 'addframework', moduleId: 'addframework', nav: true }
		]);

	}
}
