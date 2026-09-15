import type {
	DataViewRenderFieldProps,
	Field,
	NormalizedField,
	View,
} from '@wordpress/dataviews';
import type { ComponentType } from 'react';

type Rule = {
	id: number;
	action: string;
	value: string;
	type: string;
	created_at: string;
};

type DataViewsProps = {
	data: Rule[];
	children?: React.ReactNode;
	empty?: React.ReactNode;
	fields?: Field< Rule >[];
	isLoading?: boolean;
	view?: View;
	onChangeView?: ( view: View ) => void;
};

type DataFormProps = {
	data: Record< string, unknown >;
	fields?: Array< {
		id: string;
		label?: string;
		Edit?: string;
		elements?: Array< { value: string; label: string } >;
		isDisabled?: boolean;
		placeholder?: string;
		isValid?: {
			custom?: ( item: Record< string, any > ) => string | null;
		};
	} >;
	onChange?: ( changes: Record< string, unknown > ) => void;
};

export const dataViews = {
	props: undefined as DataViewsProps | undefined,
};

export function DataViews( props: DataViewsProps ) {
	dataViews.props = props;
	const { data, children, empty, fields = [], isLoading } = props;
	return (
		<>
			{ children }
			<table aria-label="Rules">
				<tbody>
					{ data.map( ( rule ) => {
						return (
							<tr key={ rule.id }>
								{ fields.map( ( field ) => {
									const FieldRenderer = field.render as
										| ComponentType<
												DataViewRenderFieldProps< Rule >
										  >
										| undefined;
									return (
										<td key={ field.id }>
											{ FieldRenderer ? (
												<FieldRenderer
													item={ rule }
													field={
														field as NormalizedField< Rule >
													}
												/>
											) : (
												String(
													rule[
														field.id as keyof Rule
													]
												)
											) }
										</td>
									);
								} ) }
							</tr>
						);
					} ) }
				</tbody>
			</table>
			{ data.length === 0 && ! isLoading && empty }
		</>
	);
}

export function DataForm( { data, fields = [], onChange }: DataFormProps ) {
	return (
		<>
			{ fields.map( ( field ) => {
				const validation = field.isValid?.custom?.(
					data as Record< string, any >
				);
				return field.Edit === 'select' ? (
					<label key={ field.id } htmlFor={ field.id }>
						{ field.label }
						<select
							id={ field.id }
							aria-label={ field.label }
							value={ String( data[ field.id ] ?? '' ) }
							disabled={ field.isDisabled }
							onChange={ ( event ) =>
								onChange?.( {
									[ field.id ]: event.target.value,
								} )
							}
						>
							{ field.elements?.map( ( element ) => (
								<option
									key={ element.value }
									value={ element.value }
								>
									{ element.label }
								</option>
							) ) }
						</select>
					</label>
				) : (
					<label key={ field.id } htmlFor={ field.id }>
						{ field.label }
						<input
							id={ field.id }
							aria-label={ field.label }
							placeholder={ field.placeholder }
							value={ String( data[ field.id ] ?? '' ) }
							disabled={ field.isDisabled }
							onChange={ ( event ) =>
								onChange?.( {
									[ field.id ]: event.target.value,
								} )
							}
						/>
						{ validation && (
							<span role="alert">{ validation }</span>
						) }
					</label>
				);
			} ) }
		</>
	);
}

export function useFormValidity(
	data: Record< string, unknown >,
	fields: DataFormProps[ 'fields' ] = []
) {
	const valueField = fields.find( ( field ) => field.id === 'value' );
	const valueError = valueField?.isValid?.custom?.(
		data as Record< string, any >
	);
	return {
		validity: undefined,
		isValid:
			Boolean( data.action && data.type && data.value ) && ! valueError,
	};
}

export namespace DataViews {
	export function FiltersToggle() {
		return <button type="button" aria-label="Add filter" />;
	}

	export function ViewConfig() {
		return <button type="button" aria-label="View options" />;
	}

	export function FiltersToggled() {
		return null;
	}

	export function Layout() {
		return null;
	}

	export function Pagination() {
		return null;
	}
}
