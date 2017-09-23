import 'semantic-ui';

export function configure (aurelia) {
	aurelia.use.instance('apiRoot','/cms/api/1.1/');
	aurelia.use.instance('apiToken','NGl8ZFUk6AvLs94pXoofIjIWUWhr2rLS');
	aurelia.use
		.standardConfiguration()
		.developmentLogging()
		.plugin('aurelia-validation')
		.plugin('aurelia-dialog');
	aurelia.start().then(a => a.setRoot());
}