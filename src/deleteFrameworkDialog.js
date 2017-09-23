import { inject } from 'aurelia-framework';
import { DialogController } from 'aurelia-dialog';

@inject(DialogController)
export class DeleteFrameworkDialog {

	constructor(dialogController) {
		this.dialogController = dialogController;
	}

	activate(framework) {
		this.framework = framework;
	}
}