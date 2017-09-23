import { inject } from 'aurelia-framework';
import { DataRepository } from 'services/dataRepository';
import { ValidationRules, ValidationController } from 'aurelia-validation';

@inject(DataRepository, ValidationController)
export class Addframework {

    selectedFile;

    constructor(dataRepository, validationController) {
        this.dataRepository = dataRepository;
        this.validationController = validationController;
        this.framework = {};

        ValidationRules
            .ensure(f => f.title)
            .required()
            .withMessage('Please enter a title.')
            .minLength(3)
            .ensure(f => f.tagline)
            .required()
            .withMessage('Please enter a tagline.')
            .minLength(3)
            .ensure(f => f.URL)
            .matches(/^((https?|ftp|smtp):\/\/)?(www.)?[a-z0-9]+\.[a-z]+(\/[a-zA-Z0-9#]+\/?)*$/)
            .withMessage('Please enter a valid URL.')
            .on(this.framework);
    }

    // dragAndDropCallback(file, data) {
    //     var dndElement = document.getElementById('drag_and_drop_file');
    //     dndElement.style.background = 'url(' + data + ') no-repeat center';
    //     dndElement.style.backgroundSize = 'contain';
    // }

    activate(params, routeConfig, navigationInstruction) {
        this.router = navigationInstruction.router;
    }


    save() {        
        this.validationController.validate().then(result => {
            if (result.valid) {
                this.dataRepository.addFile(this.selectedFiles[0]);
                this.dataRepository.addFramework(this.framework).then(framework => this.router.navigateToRoute('frameworks'));
            } else {
                return;
            }
        });
    }

    cancel() {
        this.router.navigateToRoute('frameworks/');
    }

}
